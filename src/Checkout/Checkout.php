<?php

declare(strict_types=1);

namespace IndependentMall\Checkout;

use DomainException;
use IndependentMall\Account\AddressBook;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Security\AuditLogger;
use PDO;

final class Checkout
{
    public const STATUSES = [
        'pending_payment', 'paid', 'fulfilling', 'completed', 'cancelled', 'refund_requested', 'refunded',
    ];

    public function __construct(
        private PDO $pdo,
        private Cart $cart,
        private ProductCatalog $catalog,
        private AddressBook $addresses,
        private AuditLogger $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function place(int $userId, ?int $addressId, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new DomainException('Checkout idempotency key is required.');
        }
        $existing = $this->pdo->prepare('SELECT * FROM orders WHERE user_id = :u AND idempotency_key = :k');
        $existing->execute(['u' => $userId, 'k' => $idempotencyKey]);
        $found = $existing->fetch();
        if (is_array($found)) {
            return $found;
        }
        $lines = $this->cart->lines($userId);
        if ($lines === []) {
            throw new DomainException('Cart is empty.');
        }
        foreach ($lines as $line) {
            if ($line['issue'] !== '') {
                throw new DomainException('Cart contains unavailable items.');
            }
        }
        $needsAddress = false;
        foreach ($lines as $line) {
            if ($line['product_type'] === 'physical') {
                $needsAddress = true;
            }
        }
        $snapshot = '{}';
        if ($needsAddress) {
            if ($addressId === null) {
                throw new DomainException('A shipping address is required for physical goods.');
            }
            $snapshot = $this->addresses->snapshotJson($this->addresses->owned($userId, $addressId));
        } elseif ($addressId !== null) {
            $snapshot = $this->addresses->snapshotJson($this->addresses->owned($userId, $addressId));
        }
        $goods = 0;
        foreach ($lines as $line) {
            $goods += (int) $line['price_minor'] * (int) $line['qty'];
        }
        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $publicId = 'im_' . bin2hex(random_bytes(8));
            $this->pdo->prepare(
                'INSERT INTO orders (public_id, user_id, status, currency, goods_minor, discount_minor, shipping_minor, tax_minor, payable_minor, address_snapshot, idempotency_key, created_at, updated_at)
                 VALUES (:p, :u, :st, :cur, :g, 0, 0, 0, :pay, :a, :k, :c, :x)'
            )->execute([
                'p' => $publicId,
                'u' => $userId,
                'st' => 'pending_payment',
                'cur' => 'CNY',
                'g' => $goods,
                'pay' => $goods,
                'a' => $snapshot,
                'k' => $idempotencyKey,
                'c' => $now,
                'x' => $now,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();
            $item = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, product_type, name, sku, qty, unit_minor, currency)
                 VALUES (:o, :p, :v, :t, :n, :s, :q, :u, :c)'
            );
            foreach ($lines as $line) {
                $item->execute([
                    'o' => $orderId,
                    'p' => $line['product_id'],
                    'v' => $line['id'],
                    't' => $line['product_type'],
                    'n' => $line['product_name'],
                    's' => $line['sku'],
                    'q' => $line['qty'],
                    'u' => $line['price_minor'],
                    'c' => $line['currency'],
                ]);
                $this->catalog->applyMovement(
                    (int) $line['id'],
                    0,
                    (int) $line['qty'],
                    'reserve',
                    'order:' . $publicId . ':v' . $line['id'],
                );
            }
            $this->cart->clear($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->record($userId, 'order.placed', 'order', (string) $orderId, ['public_id' => $publicId]);
        return $this->byId($orderId);
    }

    public function cancel(int $userId, int $orderId): void
    {
        $order = $this->owned($userId, $orderId);
        $this->cancelInternal($order, $userId);
    }

    public function adminCancel(int $orderId, ?int $actorId): void
    {
        $order = $this->byId($orderId);
        $this->cancelInternal($order, $actorId);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function cancelInternal(array $order, ?int $actorId): void
    {
        if ($order['status'] === 'cancelled') {
            return;
        }
        if ($order['status'] !== 'pending_payment') {
            throw new DomainException('Only unpaid orders can be cancelled.');
        }
        $this->pdo->beginTransaction();
        try {
            $claim = $this->pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = :t WHERE id = :id AND status = 'pending_payment'");
            $claim->execute(['t' => gmdate('c'), 'id' => $order['id']]);
            if ($claim->rowCount() !== 1) {
                if ($this->byId((int) $order['id'])['status'] === 'cancelled') {
                    $this->pdo->commit();
                    return;
                }
                throw new DomainException('只有未付款订单可以取消。');
            }
            $items = $this->items((int) $order['id']);
            foreach ($items as $item) {
                $this->catalog->applyMovement(
                    (int) $item['variant_id'],
                    0,
                    -(int) $item['qty'],
                    'release',
                    'cancel:' . $order['public_id'] . ':v' . $item['variant_id'],
                );
            }
            $this->audit->record($actorId, 'order.cancelled', 'order', (string) $order['id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function byId(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Order not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function byPublicId(string $publicId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE public_id = :p');
        $stmt->execute(['p' => $publicId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Order not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function owned(int $userId, int $orderId): array
    {
        $order = $this->byId($orderId);
        if ((int) $order['user_id'] !== $userId) {
            throw new DomainException('Order not found.');
        }
        return $order;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE user_id = :u ORDER BY id DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll() ?: [];
    }
}
