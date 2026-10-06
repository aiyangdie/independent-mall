<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class CurlTransport implements JsonTransport
{
    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('License server URL must be http or https.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('ext-curl is required for admin license refresh.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not start HTTP client.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('License server unreachable. Existing local document kept for grace. ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($decoded)) {
            $msg = is_array($decoded) ? (string) ($decoded['error'] ?? 'refresh failed') : 'refresh failed';
            throw new \RuntimeException('License server refused refresh. Existing local document kept for grace. ' . $msg);
        }
        return $decoded;
    }
}
