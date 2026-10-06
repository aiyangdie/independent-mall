<?php

declare(strict_types=1);

namespace IndependentMall\Update;

use IndependentMall\License\CanonicalJson;
use IndependentMall\License\OpenSslEd25519;

final class PackageVerifier
{
    /**
     * @param array<string, mixed> $manifest
     */
    public static function assertSafeToApply(array $manifest, string $artifactPath, string $publicPem, string $currentVersion): void
    {
        if (empty($manifest['signed'])) {
            throw new \DomainException('Unsigned updates must not be applied automatically.');
        }
        $sig = base64_decode((string) ($manifest['signature'] ?? ''), true);
        if ($sig === false || $sig === '') {
            throw new \DomainException('Update signature missing.');
        }
        $payload = $manifest;
        unset($payload['signature'], $payload['signed']);
        if (!OpenSslEd25519::verify(CanonicalJson::encode($payload), $sig, $publicPem)) {
            throw new \DomainException('Update signature verification failed.');
        }
        if (!is_file($artifactPath)) {
            throw new \DomainException('Update artifact missing.');
        }
        $hash = hash_file('sha256', $artifactPath);
        if (!hash_equals((string) ($manifest['sha256'] ?? ''), (string) $hash)) {
            throw new \DomainException('Update SHA-256 mismatch.');
        }
        $raw = (string) file_get_contents($artifactPath);
        if (str_contains($artifactPath, '.php') || str_starts_with(ltrim($raw), '<?php') || str_contains($raw, "\0")) {
            throw new \DomainException('Refusing to apply an executable or binary payload.');
        }
        $need = (string) ($manifest['php'] ?? '>=8.2');
        if ($need === '>=8.2' && version_compare(PHP_VERSION, '8.2.0', '<')) {
            throw new \DomainException('PHP is too old for this update.');
        }
        $fromMajor = self::major($currentVersion);
        $toMajor = self::major((string) ($manifest['version'] ?? ''));
        if ($toMajor > $fromMajor) {
            throw new \DomainException('Refusing a silent major-version upgrade.');
        }
    }

    private static function major(string $version): int
    {
        $part = explode('.', ltrim($version, 'v'))[0] ?? '0';
        return max(0, (int) preg_replace('/\D/', '', $part));
    }
}
