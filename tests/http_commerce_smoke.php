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

function csrf(string $html): string
{
    if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('missing csrf');
    }
    return $m[1];
}

function post(string $url, string &$cookieHeader, array $fields): array
{
    return fetch($url, $cookieHeader, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        'extra_headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
}

[$st, , $health] = fetch($base . '/healthz', $cookieHeader);
$json = json_decode($health, true);
if ($st !== 200 || ($json['license_required'] ?? true) !== false) {
    fwrite(STDERR, "health failed\n");
    exit(1);
}

$email = 'buyer' . bin2hex(random_bytes(3)) . '@example.invalid';
[$st, , $reg] = fetch($base . '/register', $cookieHeader);
[$st, $hdr, $body] = post($base . '/register', $cookieHeader, [
    '_csrf' => csrf($reg),
    'email' => $email,
    'username' => 'u' . substr(bin2hex(random_bytes(4)), 0, 8),
    'password' => 'correct-horse-1',
]);
if ($st !== 302) {
    fwrite(STDERR, "register failed $st $body\n");
    exit(1);
}

[$st, , $addr] = fetch($base . '/account/addresses', $cookieHeader);
[$st] = post($base . '/account/addresses', $cookieHeader, [
    '_csrf' => csrf($addr),
    'label' => 'Home',
    'recipient' => 'Buyer',
    'phone' => '000',
    'region' => 'Region',
    'line1' => '1 Example Street',
    'is_default' => '1',
]);
if ($st !== 302) {
    fwrite(STDERR, "address failed $st\n");
    exit(1);
}

[$st, , $p] = fetch($base . '/p/ceramic-mug', $cookieHeader);
if ($st !== 200 || !preg_match('/name="variant_id" value="(\d+)"/', $p, $vm)) {
    fwrite(STDERR, "product page failed $st\n");
    exit(1);
}
[$st] = post($base . '/cart/add', $cookieHeader, [
    '_csrf' => csrf($p),
    'variant_id' => $vm[1],
    'qty' => '1',
]);
if ($st !== 302) {
    fwrite(STDERR, "add cart failed $st\n");
    exit(1);
}

[$st, , $chk] = fetch($base . '/checkout', $cookieHeader);
if ($st !== 200 || !preg_match('/name="address_id">[\s\S]*?<option value="(\d+)"/', $chk, $am)) {
    fwrite(STDERR, "checkout form failed $st\n");
    exit(1);
}
[$st, $hdr] = post($base . '/checkout', $cookieHeader, [
    '_csrf' => csrf($chk),
    'address_id' => $am[1],
    'idempotency_key' => 'http-' . bin2hex(random_bytes(6)),
]);
if ($st !== 302 || !preg_match('#Location:\s*/orders/(\d+)#i', $hdr, $om)) {
    fwrite(STDERR, "place order failed $st $hdr\n");
    exit(1);
}
$orderId = $om[1];
[$st, , $order] = fetch($base . '/orders/' . $orderId, $cookieHeader);
[$st, $hdr] = post($base . '/orders/' . $orderId . '/pay-fake', $cookieHeader, [
    '_csrf' => csrf($order),
    'outcome' => 'succeeded',
]);
if ($st !== 302 || !preg_match('#Location:\s*/orders/\d+/result#i', $hdr)) {
    fwrite(STDERR, "pay failed $st $hdr\n");
    exit(1);
}
[$st, , $result] = fetch($base . '/orders/' . $orderId . '/result', $cookieHeader);
if ($st !== 200 || !str_contains($result, 'Payment result')) {
    fwrite(STDERR, "payment result page failed $st\n");
    exit(1);
}
[$st, , $paid] = fetch($base . '/orders/' . $orderId, $cookieHeader);
if ($st !== 200 || !str_contains($paid, 'completed')) {
    fwrite(STDERR, "paid order missing completed status\n");
    exit(1);
}
[$st] = post($base . '/orders/' . $orderId . '/refund', $cookieHeader, [
    '_csrf' => csrf($paid),
    'amount_minor' => '1800',
    'reason' => 'http-smoke',
    'idempotency_key' => 'rf-http-' . $orderId,
]);
if ($st !== 302) {
    fwrite(STDERR, "refund failed $st\n");
    exit(1);
}
echo "HTTP commerce smoke ok order={$orderId}\n";
