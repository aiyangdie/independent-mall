<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/healthz') {
    echo json_encode(['status' => 'ok', 'role' => 'license-server', 'exec_php' => false]);
    exit;
}

$var = $root . '/var';
$pdo = new PDO('sqlite:' . $var . '/licenses.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$store = new Store($pdo);

if ($method === 'POST' && $path === '/activate') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $payload = $store->activate(
            (string) ($body['license_id'] ?? ''),
            (string) ($body['token'] ?? ''),
            (string) ($body['instance_id'] ?? ''),
        );
        $private = (string) file_get_contents($var . '/private.pem');
        $doc = Signer::sign($payload, $private);
        echo json_encode($doc, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/unbind') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $id = (string) ($body['license_id'] ?? '');
        $token = (string) ($body['token'] ?? '');
        $stmt = $pdo->prepare('SELECT token_hash FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            throw new DomainException('Unknown license.');
        }
        $store->unbind($id);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $path === '/refresh') {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $payload = $store->refresh(
            (string) ($body['license_id'] ?? ''),
            (string) ($body['token'] ?? ''),
            (string) ($body['instance_id'] ?? ''),
        );
        $private = (string) file_get_contents($var . '/private.pem');
        $doc = Signer::sign($payload, $private);
        echo json_encode($doc, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
