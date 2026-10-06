<?php

declare(strict_types=1);

$base = 'http://127.0.0.1:8090';
$cookieHeader = '';

function fetch(string $url, string &$cookieHeader, array $opts = []): array
{
    $ch = curl_init($url);
    $reqHeaders = [];
    if ($cookieHeader !== '') {
        $reqHeaders[] = 'Cookie: ' . $cookieHeader;
    }
    if (isset($opts['extra_headers']) && is_array($opts['extra_headers'])) {
        $reqHeaders = array_merge($reqHeaders, $opts['extra_headers']);
        unset($opts['extra_headers']);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $reqHeaders,
    ] + $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = substr($raw, 0, $headerSize);
    if (preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $headerBlock, $m)) {
        $cookieHeader = $m[1];
    }
    return [$status, $headerBlock, substr($raw, $headerSize)];
}

[$st, , $home] = fetch($base . '/', $cookieHeader);
if ($st !== 200 || !str_contains($home, 'lawful trade')) {
    fwrite(STDERR, "home failed status=$st\n");
    exit(1);
}
[$st, , $health] = fetch($base . '/healthz', $cookieHeader);
$json = json_decode($health, true);
if ($st !== 200 || ($json['license_required'] ?? true) !== false || ($json['network_required'] ?? true) !== false) {
    fwrite(STDERR, "health failed\n");
    exit(1);
}
[$st, , $login] = fetch($base . '/admin/login', $cookieHeader);
if ($st !== 200 || !preg_match('/name="_csrf" value="([^"]+)"/', $login, $m)) {
    fwrite(STDERR, "login form failed status=$st\n");
    exit(1);
}
[$st, $headers, $body] = fetch($base . '/admin/login', $cookieHeader, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $m[1],
        'identity' => 'admin@example.invalid',
        'password' => 'local-dev-only-change',
    ]),
    'extra_headers' => ['Content-Type: application/x-www-form-urlencoded'],
]);
if ($st !== 302 || !str_contains($headers, '/admin')) {
    fwrite(STDERR, "login post failed status=$st body=" . substr($body, 0, 240) . "\n");
    exit(1);
}
[$st, , $dash] = fetch($base . '/admin', $cookieHeader);
if ($st !== 200 || !str_contains($dash, 'admin@example.invalid') || !str_contains($dash, '/admin/audit?action=license.')) {
    fwrite(STDERR, "dashboard failed status=$st\n");
    exit(1);
}
echo "HTTP smoke ok\n";
