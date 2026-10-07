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
        $this->pdo->beginTransaction();
        try {
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
        $consume = $this->pdo->prepare("UPDATE digital_deliveries SET download_count = download_count + 1 WHERE id = :id AND token_hash = :h AND revoked = 0 AND expires_at >= :now AND download_count < max_downloads AND EXISTS (SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id = digital_deliveries.order_item_id AND o.status IN ('paid','fulfilling','completed'))");
        $consume->execute(['id' => $row['id'], 'h' => $hash, 'now' => gmdate('c')]);
        if ($consume->rowCount() !== 1) {
            throw new DomainException('下载链接已失效或下载次数已用完。');
        }
        $this->audit->record((int) $order['user_id'], 'digital.download', 'delivery', (string) $row['id'], []);
        $this->pdo->commit();
        return ['label' => (string) $row['label'], 'body' => (string) $row['body']];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Rotate a customer's link, without replenishing its lifespan or download allowance. */
    public function renewLink(int $userId, int $orderId, int $deliveryId): string
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE orders SET updated_at = updated_at WHERE id = :id')->execute(['id' => $orderId]);
            $order = $this->checkout->owned($userId, $orderId);
            if (!in_array($order['status'], ['paid', 'fulfilling', 'completed'], true)) {
                throw new DomainException('订单尚未付款或正在处理退款，不能获取下载链接。');
            }
            $raw = bin2hex(random_bytes(24));
            $stmt = $this->pdo->prepare('UPDATE digital_deliveries SET token_hash = :h WHERE id = :id AND revoked = 0 AND expires_at >= :now AND download_count < max_downloads AND order_item_id IN (SELECT id FROM order_items WHERE order_id = :o)');
            $stmt->execute(['h' => hash_hmac('sha256', $raw, $this->tokenSecret), 'id' => $deliveryId, 'now' => gmdate('c'), 'o' => $orderId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('交付物不存在、已过期或下载次数已用完。');
            }
            $this->audit->record($userId, 'digital.link_renewed', 'delivery', (string) $deliveryId);
            $this->pdo->commit();
            return $raw;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
