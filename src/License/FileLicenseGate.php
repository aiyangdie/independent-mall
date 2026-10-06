<?php

declare(strict_types=1);

namespace IndependentMall\License;

use IndependentMall\Modules\LicenseGate;

final class FileLicenseGate implements LicenseGate
{
    public function __construct(
        private string $documentPath,
        private string $publicKeyPath,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public static function fromRoot(string $root, ?Clock $clock = null): LicenseGate
    {
        $doc = $root . '/var/license.json';
        $pub = $root . '/var/license-public.pem';
        if (!is_file($pub)) {
            $pub = $root . '/config/license-public.pem';
        }
        if (!is_file($doc) || !is_file($pub)) {
            return new \IndependentMall\Modules\AbsentLicense();
        }
        return new self($doc, $pub, $clock ?? new SystemClock());
    }

    public function allows(array $entitlements): bool
    {
        if ($entitlements === []) {
            return true;
        }
        $payload = $this->payload();
        if ($payload === null) {
            return false;
        }
        if (!empty($payload['revoked'])) {
            return false;
        }
        foreach ($entitlements as $need) {
            $list = $payload['entitlements'] ?? [];
            if (!is_array($list) || !in_array($need, $list, true)) {
                return false;
            }
        }
        $now = $this->clock->now();
        $issued = strtotime((string) ($payload['issued_at'] ?? '')) ?: 0;
        if ($issued > $now + 300) {
            return false;
        }
        $expires = strtotime((string) ($payload['expires_at'] ?? '')) ?: 0;
        $graceDays = max(0, (int) ($payload['grace_days'] ?? 14));
        if ($now <= $expires + 300) {
            return true;
        }
        $graceUntil = $expires + ($graceDays * 86400);
        $refreshed = strtotime((string) ($payload['last_refresh_at'] ?? $payload['issued_at'] ?? '')) ?: 0;
        return $refreshed > 0 && $refreshed <= $expires + 300 && $now <= $graceUntil;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $raw = @file_get_contents($this->documentPath);
        $pem = @file_get_contents($this->publicKeyPath);
        if (!is_string($raw) || !is_string($pem) || $raw === '' || $pem === '') {
            return null;
        }
        try {
            $env = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($env) || ($env['alg'] ?? '') !== 'Ed25519' || !isset($env['payload'], $env['signature']) || !is_array($env['payload'])) {
            return null;
        }
        $payload = $env['payload'];
        $sig = base64_decode((string) $env['signature'], true);
        if ($sig === false) {
            return null;
        }
        $canonical = CanonicalJson::encode($payload);
        if (!OpenSslEd25519::verify($canonical, $sig, $pem)) {
            return null;
        }
        return $payload;
    }
}
