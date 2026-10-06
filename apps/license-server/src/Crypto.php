<?php

declare(strict_types=1);

namespace IndependentMall\LicenseServer;

final class Crypto
{
    public static function ed25519Type(): int
    {
        $candidates = [
            dirname(__DIR__, 3) . '/config/openssl.cnf',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        ];
        foreach ($candidates as $cnf) {
            if (is_file($cnf)) {
                putenv('OPENSSL_CONF=' . $cnf);
                break;
            }
        }
        if (defined('OPENSSL_PKEY_ED25519')) {
            return (int) OPENSSL_PKEY_ED25519;
        }
        if (defined('OPENSSL_KEYTYPE_ED25519')) {
            return (int) OPENSSL_KEYTYPE_ED25519;
        }
        throw new \RuntimeException('Ed25519 is not available. License server refuses to start.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function sort(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $data[$key] = self::sort($value);
            }
        }
        ksort($data);
        return $data;
    }
}
