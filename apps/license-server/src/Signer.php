<?php

declare(strict_types=1);

namespace IndependentMall\LicenseServer;

final class Signer
{
    /**
     * @return array{private:string,public:string}
     */
    public static function generateKeypair(): array
    {
        Crypto::ed25519Type();
        $key = openssl_pkey_new(['private_key_type' => Crypto::ed25519Type()]);
        if ($key === false) {
            return self::generateViaOpenSslBinary();
        }
        if (!openssl_pkey_export($key, $private)) {
            throw new \RuntimeException('Could not export the private key.');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !isset($details['key'])) {
            throw new \RuntimeException('Could not export the public key.');
        }
        return ['private' => $private, 'public' => (string) $details['key']];
    }

    /**
     * @return array{private:string,public:string}
     */
    private static function generateViaOpenSslBinary(): array
    {
        $bin = self::opensslBinary();
        $privFile = tempnam(sys_get_temp_dir(), 'imk');
        $pubFile = $privFile . '.pub';
        $cmd = escapeshellarg($bin) . ' genpkey -algorithm ED25519 -out ' . escapeshellarg($privFile);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($privFile) || filesize($privFile) < 16) {
            @unlink($privFile);
            throw new \RuntimeException('Could not generate an Ed25519 key. Refusing to issue licenses. ' . implode(' ', $out));
        }
        $pubCmd = escapeshellarg($bin) . ' pkey -in ' . escapeshellarg($privFile) . ' -pubout -out ' . escapeshellarg($pubFile);
        exec($pubCmd . ' 2>&1', $out2, $code2);
        $private = (string) file_get_contents($privFile);
        $public = is_file($pubFile) ? (string) file_get_contents($pubFile) : '';
        @unlink($privFile);
        @unlink($pubFile);
        if ($code2 !== 0 || $public === '' || !str_contains($private, 'PRIVATE')) {
            throw new \RuntimeException('Could not export Ed25519 keys via openssl CLI.');
        }
        return ['private' => $private, 'public' => $public];
    }

    private static function opensslBinary(): string
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
        throw new \RuntimeException('openssl CLI not found; cannot generate Ed25519 keys on this host.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{alg:string,payload:array<string,mixed>,signature:string}
     */
    public static function sign(array $payload, string $privatePem): array
    {
        Crypto::ed25519Type();
        $canonical = Crypto::encode($payload);
        $key = openssl_pkey_get_private($privatePem);
        $signature = '';
        if ($key !== false && openssl_sign($canonical, $signature, $key)) {
            return [
                'alg' => 'Ed25519',
                'payload' => $payload,
                'signature' => base64_encode($signature),
            ];
        }
        $signature = self::signViaOpenSslBinary($canonical, $privatePem);
        return [
            'alg' => 'Ed25519',
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }

    private static function signViaOpenSslBinary(string $canonical, string $privatePem): string
    {
        $bin = self::opensslBinary();
        $keyFile = tempnam(sys_get_temp_dir(), 'ims');
        $inFile = $keyFile . '.in';
        $sigFile = $keyFile . '.sig';
        file_put_contents($keyFile, $privatePem);
        file_put_contents($inFile, $canonical);
        $cmd = escapeshellarg($bin) . ' pkeyutl -sign -inkey ' . escapeshellarg($keyFile)
            . ' -rawin -in ' . escapeshellarg($inFile) . ' -out ' . escapeshellarg($sigFile);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $sig = is_file($sigFile) ? (string) file_get_contents($sigFile) : '';
        @unlink($keyFile);
        @unlink($inFile);
        @unlink($sigFile);
        if ($code !== 0 || $sig === '') {
            throw new \RuntimeException('Signing failed. ' . implode(' ', $out));
        }
        return $sig;
    }
}
