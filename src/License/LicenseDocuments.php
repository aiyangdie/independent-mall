<?php

declare(strict_types=1);

namespace IndependentMall\License;

use DomainException;

/**
 * Paths and atomic writes for the offline license document and pinned vendor public key.
 * License import must never replace the trust public key.
 */
final class LicenseDocuments
{
    public static function documentPath(string $root): string
    {
        return $root . '/var/license.json';
    }

    /**
     * Packaged vendor key wins over a local var copy so imports cannot silently
     * override a release-shipped trust anchor by writing var/license-public.pem alone.
     * Local installs without a packaged key use var/license-public.pem (set via trust-key).
     */
    public static function publicKeyPath(string $root): ?string
    {
        $packaged = $root . '/config/license-public.pem';
        if (is_file($packaged) && filesize($packaged) > 0) {
            return $packaged;
        }
        $local = $root . '/var/license-public.pem';
        if (is_file($local) && filesize($local) > 0) {
            return $local;
        }
        return null;
    }

    public static function localPublicKeyPath(string $root): string
    {
        return $root . '/var/license-public.pem';
    }

    public static function packagedPublicKeyPath(string $root): string
    {
        return $root . '/config/license-public.pem';
    }

    public static function fingerprint(string $pem): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem) ?? '';
        return hash('sha256', $body);
    }

    public static function assertPublicPem(string $pem): void
    {
        $pem = trim($pem);
        if ($pem === '' || (!str_contains($pem, 'BEGIN PUBLIC KEY') && !str_contains($pem, 'BEGIN OPENSSH'))) {
            throw new DomainException('Public key PEM is invalid.');
        }
        if (str_contains($pem, 'PRIVATE KEY')) {
            throw new DomainException('Private keys must not be stored on the shop.');
        }
    }

    /**
     * Verify $json against the pinned trust key using temp files, then atomically replace license.json.
     * On failure the existing document and trust key are left untouched.
     *
     * @param callable(array<string, mixed>):void|null $afterVerify throws to abort without replacing
     * @return array<string, mixed>
     */
    public static function importDocument(string $root, string $json, ?Clock $clock = null, ?callable $afterVerify = null): array
    {
        $json = trim($json);
        if ($json === '') {
            throw new DomainException('License JSON is required.');
        }
        $pubPath = self::publicKeyPath($root);
        if ($pubPath === null) {
            throw new DomainException(
                'Vendor public key is not configured. Install the trust public key before importing a license.'
            );
        }
        $var = $root . '/var';
        if (!is_dir($var) && !mkdir($var, 0775, true) && !is_dir($var)) {
            throw new DomainException('Could not create var directory for license storage.');
        }
        $tmp = $var . '/license.' . bin2hex(random_bytes(6)) . '.tmp.json';
        if (file_put_contents($tmp, $json) === false) {
            throw new DomainException('Could not write temporary license file.');
        }
        try {
            $gate = new FileLicenseGate($tmp, $pubPath, $clock ?? new SystemClock());
            $payload = $gate->payload();
            if ($payload === null) {
                throw new DomainException('License document failed verification. Existing license was left unchanged.');
            }
            if ($afterVerify !== null) {
                $afterVerify($payload);
            }
            self::replaceFile(self::documentPath($root), $json);
            return $payload;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Install or rotate the local trust public key (var/). Refuses when a packaged config key is present.
     * Does not modify license.json.
     */
    public static function installTrustKey(string $root, string $pem, string $confirmFingerprint = ''): string
    {
        self::assertPublicPem($pem);
        $pem = trim($pem) . (str_ends_with(trim($pem), "\n") ? '' : "\n");
        $packaged = self::packagedPublicKeyPath($root);
        if (is_file($packaged) && filesize($packaged) > 0) {
            throw new DomainException(
                'A packaged vendor public key is present under config/. Local import cannot replace it; use the release key-rotation process.'
            );
        }
        $target = self::localPublicKeyPath($root);
        $fp = self::fingerprint($pem);
        if (is_file($target) && filesize($target) > 0) {
            $current = (string) file_get_contents($target);
            $currentFp = self::fingerprint($current);
            if (!hash_equals($currentFp, $fp)) {
                if ($confirmFingerprint === '' || !hash_equals($currentFp, strtolower(trim($confirmFingerprint)))) {
                    throw new DomainException(
                        'Trust key rotation requires confirm_fingerprint matching the current key fingerprint: ' . $currentFp
                    );
                }
            }
        }
        $var = $root . '/var';
        if (!is_dir($var) && !mkdir($var, 0775, true) && !is_dir($var)) {
            throw new DomainException('Could not create var directory for the trust public key.');
        }
        self::replaceFile($target, $pem);
        return $fp;
    }

    private static function replaceFile(string $target, string $contents): void
    {
        $dir = dirname($target);
        $tmp = $dir . '/' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new DomainException('Could not write temporary file for ' . basename($target) . '.');
        }
        if (is_file($target)) {
            $backup = $target . '.' . bin2hex(random_bytes(4)) . '.bak';
            if (!@rename($target, $backup)) {
                @unlink($tmp);
                throw new DomainException('Could not move the existing file aside safely.');
            }
            if (!@rename($tmp, $target)) {
                @rename($backup, $target);
                @unlink($tmp);
                throw new DomainException('Could not install the new file; previous content was restored.');
            }
            @unlink($backup);
            return;
        }
        if (!@rename($tmp, $target)) {
            if (@copy($tmp, $target) === true) {
                @unlink($tmp);
                return;
            }
            @unlink($tmp);
            throw new DomainException('Could not install ' . basename($target) . '.');
        }
    }
}
