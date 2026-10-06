<?php

declare(strict_types=1);

namespace IndependentMall\Fulfillment;

use DomainException;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Security\AuditLogger;
use PDO;

final class DigitalFulfillment
{
    public function __construct(
        private PDO $pdo,
        private Checkout $checkout,
        private AuditLogger $audit,
        private string $tokenSecret,
    ) {
    }

    /**
     * @return list<string> plaintext tokens (returned once at fulfillment for tests/mail; never stored)
     */
    public function fulfillPaidOrder(int $orderId): array
    {
        $issued = [];
        foreach ($this->checkout->items($orderId) as $item) {
            if ($item['product_type'] !== 'digital') {
                continue;
            }
            $exists = $this->pdo->prepare('SELECT id FROM digital_deliveries WHERE order_item_id = :i');
            $exists->execute(['i' => $item['id']]);
            if ($exists->fetch()) {
                continue;
            }
            $assetStmt = $this->pdo->prepare('SELECT * FROM digital_assets WHERE product_id = :p ORDER BY id LIMIT 1');
            $assetStmt->execute(['p' => $item['product_id']]);
            $asset = $assetStmt->fetch();
            if (!is_array($asset)) {
                continue;
            }
            $raw = bin2hex(random_bytes(24));
            $this->pdo->prepare(
                'INSERT INTO digital_deliveries (order_item_id, asset_id, token_hash, expires_at, max_downloads, download_count, revoked, created_at)
                 VALUES (:o, :a, :h, :e, 3, 0, 0, :c)'
            )->execute([
                'o' => $item['id'],
                'a' => $asset['id'],
                'h' => hash_hmac('sha256', $raw, $this->tokenSecret),
                'e' => gmdate('c', time() + 86400 * 7),
                'c' => gmdate('c'),
            ]);
            $issued[] = $raw;
        }
        if ($issued !== []) {
            $this->audit->record(null, 'digital.fulfilled', 'order', (string) $orderId, ['count' => count($issued)]);
        }
        return $issued;
    }

    /**
     * @return array{label:string,body:string}
     */
    public function download(string $rawToken): array
    {
        $hash = hash_hmac('sha256', $rawToken, $this->tokenSecret);
        $stmt = $this->pdo->prepare(
            'SELECT d.*, a.label, a.body, oi.order_id FROM digital_deliveries d
             JOIN digital_assets a ON a.id = d.asset_id
             JOIN order_items oi ON oi.id = d.order_item_id
             WHERE d.token_hash = :h'
        );
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['revoked'] === 1) {
            throw new DomainException('Delivery token is invalid.');
        }
        $order = $this->checkout->byId((int) $row['order_id']);
        if (!in_array($order['status'], ['paid', 'fulfilling', 'completed'], true)) {
            throw new DomainException('Order is not paid.');
        }
        if ($row['expires_at'] < gmdate('c')) {
            throw new DomainException('Delivery token expired.');
        }
        if ((int) $row['download_count'] >= (int) $row['max_downloads']) {
            throw new DomainException('Download limit reached.');
        }
        $this->pdo->prepare('UPDATE digital_deliveries SET download_count = download_count + 1 WHERE id = :id')
            ->execute(['id' => $row['id']]);
        $this->audit->record((int) $order['user_id'], 'digital.download', 'delivery', (string) $row['id'], []);
        return ['label' => (string) $row['label'], 'body' => (string) $row['body']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.expires_at, d.max_downloads, d.download_count, d.revoked, a.label
             FROM digital_deliveries d
             JOIN order_items oi ON oi.id = d.order_item_id
             JOIN digital_assets a ON a.id = d.asset_id
             WHERE oi.order_id = :o'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll() ?: [];
    }
}
