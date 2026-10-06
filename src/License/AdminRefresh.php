<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class AdminRefresh
{
    public function __construct(
        private string $root,
        private JsonTransport $transport,
    ) {
    }

    public function refresh(string $serverUrl, string $licenseId, string $token, string $instanceId): void
    {
        $serverUrl = trim($serverUrl);
        if ($serverUrl === '') {
            throw new \DomainException('LICENSE_SERVER_URL is empty. Paste an offline document instead of calling a server.');
        }
        $licenseId = trim($licenseId);
        $token = trim($token);
        $instanceId = trim($instanceId);
        if ($licenseId === '' || $token === '') {
            throw new \DomainException('License id and token are required.');
        }
        if (preg_match('/^[a-zA-Z0-9._:-]{3,128}$/', $instanceId) !== 1) {
            throw new \DomainException('Instance id is not acceptable.');
        }
        $local = FileLicenseGate::fromRoot($this->root);
        if ($local instanceof FileLicenseGate) {
            $current = $local->payload();
            if (is_array($current)) {
                if ((string) ($current['license_id'] ?? '') !== $licenseId) {
                    throw new \DomainException('Refresh license id does not match the local document.');
                }
                if ((string) ($current['instance_id'] ?? '') !== $instanceId) {
                    throw new \DomainException('Refresh instance id does not match the local document.');
                }
            }
        }
        $url = rtrim($serverUrl, '/') . '/refresh';
        try {
            $doc = $this->transport->postJson($url, [
                'license_id' => $licenseId,
                'token' => $token,
                'instance_id' => $instanceId,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $json = json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        try {
            LicenseDocuments::importDocument($this->root, $json, null, static function (array $payload) use ($licenseId, $instanceId): void {
                if ((string) ($payload['license_id'] ?? '') !== $licenseId
                    || (string) ($payload['instance_id'] ?? '') !== $instanceId) {
                    throw new \RuntimeException(
                        'Refreshed document does not match this shop instance. Existing local document kept for grace.'
                    );
                }
            });
        } catch (\DomainException $e) {
            throw new \RuntimeException($e->getMessage() . ' Existing local document kept for grace.', 0, $e);
        }
    }
}
