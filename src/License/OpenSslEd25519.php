<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class OpenSslEd25519
{
    public static function type(): int
    {
        self::loadConfig();
        if (defined('OPENSSL_PKEY_ED25519')) {
            return (int) OPENSSL_PKEY_ED25519;
        }
        if (defined('OPENSSL_KEYTYPE_ED25519')) {
            return (int) OPENSSL_KEYTYPE_ED25519;
        }
        throw new \RuntimeException('Ed25519 is not available in this OpenSSL build. License checks fail closed.');
    }

    public static function loadConfig(): void
    {
        $candidates = [
            dirname(__DIR__, 2) . '/config/openssl.cnf',
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        ];
        foreach ($candidates as $cnf) {
            if (is_file($cnf)) {
                putenv('OPENSSL_CONF=' . $cnf);
                break;
            }
        }
    }

    public static function sign(string $payload, string $privatePem): string
    {
        self::loadConfig();
        $key = openssl_pkey_get_private($privatePem);
        $signature = '';
        if ($key !== false && openssl_sign($payload, $signature, $key) && $signature !== '') {
            return $signature;
        }
        return self::signViaCli($payload, $privatePem);
    }

    public static function verify(string $payload, string $signature, string $publicPem): bool
    {
        OpenSslEd25519::loadConfig();
        $key = openssl_pkey_get_public($publicPem);
        if ($key === false) {
            return false;
        }
        $ok = openssl_verify($payload, $signature, $key);
        if ($ok === 1) {
            return true;
        }
        return self::verifyViaCli($payload, $signature, $publicPem);
    }

    private static function verifyViaCli(string $payload, string $signature, string $publicPem): bool
    {
        $bin = self::opensslBinary();
        if ($bin === null) {
            return false;
        }
        $base = tempnam(sys_get_temp_dir(), 'imv');
        $keyFile = $base . '.pem';
        $inFile = $base . '.in';
        $sigFile = $base . '.sig';
        file_put_contents($keyFile, $publicPem);
        file_put_contents($inFile, $payload);
        file_put_contents($sigFile, $signature);
        $cmd = escapeshellarg($bin) . ' pkeyutl -verify -pubin -inkey ' . escapeshellarg($keyFile)
            . ' -rawin -in ' . escapeshellarg($inFile) . ' -sigfile ' . escapeshellarg($sigFile);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        @unlink($keyFile);
        @unlink($inFile);
        @unlink($sigFile);
        @unlink($base);
        $text = implode("\n", $out);
        return $code === 0 && str_contains(strtolower($text), 'success');
    }

    private static function signViaCli(string $payload, string $privatePem): string
    {
        $bin = self::opensslBinary();
        if ($bin === null) {
            throw new \RuntimeException('Could not sign with Ed25519.');
        }
        $base = tempnam(sys_get_temp_dir(), 'ims');
        $keyFile = $base . '.pem';
        $inFile = $base . '.in';
        $sigFile = $base . '.sig';
        file_put_contents($keyFile, $privatePem);
        file_put_contents($inFile, $payload);
        $cmd = escapeshellarg($bin) . ' pkeyutl -sign -inkey ' . escapeshellarg($keyFile)
            . ' -rawin -in ' . escapeshellarg($inFile) . ' -out ' . escapeshellarg($sigFile);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $sig = is_file($sigFile) ? (string) file_get_contents($sigFile) : '';
        @unlink($keyFile);
        @unlink($inFile);
        @unlink($sigFile);
        @unlink($base);
        if ($code !== 0 || $sig === '') {
            throw new \RuntimeException('Could not sign with Ed25519. ' . implode(' ', $out));
        }
        return $sig;
    }

    private static function opensslBinary(): ?string
    {
        $candidates = [
            getenv('OPENSSL_BIN') ?: '',
            'openssl',
            'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
            dirname(PHP_BINARY) . '\\openssl.exe',
        ];
        foreach ($candidates as $bin) {
            if ($bin === '') {
                continue;
            }
            $out = [];
            $code = 0;
            exec(escapeshellarg($bin) . ' version 2>&1', $out, $code);
            if ($code === 0) {
                return $bin;
            }
        }
        return null;
    }
}
