<?php

declare(strict_types=1);

$base = 'http://127.0.0.1:8090';
$cookie = '';

function fetchStore(string $url, string &$cookie): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerBlock = substr($raw, 0, $headerSize);
    if (preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $headerBlock, $m)) {
        $cookie = $m[1];
    }
    return [$status, substr($raw, $headerSize)];
}

[$st, $health] = fetchStore($base . '/healthz', $cookie);
$json = json_decode($health, true);
if ($st !== 200 || ($json['edition'] ?? '') !== 'community' || ($json['license_required'] ?? true) !== false || ($json['network_required'] ?? true) !== false) {
    fwrite(STDERR, "health failed $st $health\n");
    exit(1);
}
[$st, $home] = fetchStore($base . '/', $cookie);
if ($st !== 200 || !str_contains($home, 'lawful trade') || !str_contains($home, '/c/physical-goods')) {
    fwrite(STDERR, "home failed $st\n");
    exit(1);
}
[$st, $search] = fetchStore($base . '/search?q=Hidden', $cookie);
if ($st !== 200 || str_contains($search, 'Hidden draft')) {
    fwrite(STDERR, "search failed $st\n");
    exit(1);
}
[$st, $cat] = fetchStore($base . '/c/physical-goods', $cookie);
if ($st !== 200) {
    fwrite(STDERR, "category failed $st\n");
    exit(1);
}
[$st, $missing] = fetchStore($base . '/c/not-a-real-category', $cookie);
if ($st !== 404) {
    fwrite(STDERR, "missing category expected 404 got $st\n");
    exit(1);
}
[$st] = fetchStore($base . '/admin/payments', $cookie);
if ($st !== 302) {
    fwrite(STDERR, "guest payments expected 302 got $st\n");
    exit(1);
}
[$st] = fetchStore($base . '/install', $cookie);
if ($st !== 409 && $st !== 200) {
    fwrite(STDERR, "install page expected 409 or 200 got $st\n");
    exit(1);
}
[$st, $legal] = fetchStore($base . '/legal/prohibited', $cookie);
if ($st !== 200 || !str_contains($legal, 'account trading') || !str_contains($legal, 'Draft only')) {
    fwrite(STDERR, "legal page failed $st\n");
    exit(1);
}
echo "HTTP storefront smoke ok\n";
