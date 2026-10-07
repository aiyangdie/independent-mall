<?php

declare(strict_types=1);

namespace IndependentMall\Fulfillment;

use DomainException;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Security\AuditLogger;
use PDO;

/** One physical parcel per order in this first version; no fabricated carrier events. */
final class Shipping
{
    public function __construct(private PDO $pdo, private Checkout $checkout, private AuditLogger $audit)
    {
    }

    public function ship(int $orderId, int $actorId, string $carrier, string $trackingNumber, string $idempotencyKey): int
    {
        $this->assertAdministrator($actorId);
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);
        $idempotencyKey = trim($idempotencyKey);
        if ($carrier === '' || mb_strlen($carrier) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $carrier)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{2,79}$/D', $trackingNumber) !== 1
            || $idempotencyKey === '' || strlen($idempotencyKey) > 160) {
            throw new DomainException('请填写有效的物流公司和物流单号。');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lock($orderId);
            $order = $this->checkout->byId($orderId);
            $shipment = $this->forOrder($orderId);
            if ($shipment !== null) {
                if ($shipment['carrier'] === $carrier && $shipment['tracking_number'] === $trackingNumber
                    && $shipment['idempotency_key'] === $idempotencyKey) {
                    $this->pdo->commit();
                    return (int) $shipment['id'];
                }
                throw new DomainException('此订单已发货，不能重复填写不同的物流单。');
            }
            if ($order['status'] !== 'paid') {
                throw new DomainException('只有已付款且未申请退款的订单可以发货。');
            }
            $physical = array_filter($this->checkout->items($orderId), fn (array $item) => $item['product_type'] === 'physical');
            if ($physical === []) {
                throw new DomainException('此订单没有需要物流配送的实物商品。');
            }
            $now = gmdate('c');
            $this->pdo->prepare('INSERT INTO shipments (order_id, carrier, tracking_number, status, idempotency_key, shipped_by, shipped_at, updated_at) VALUES (:o, :c, :n, :s, :k, :a, :t, :u)')
                ->execute(['o' => $orderId, 'c' => $carrier, 'n' => $trackingNumber, 's' => 'shipped', 'k' => $idempotencyKey, 'a' => $actorId, 't' => $now, 'u' => $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE orders SET status = 'fulfilling', updated_at = :t WHERE id = :id")
                ->execute(['t' => $now, 'id' => $orderId]);
            $this->audit->record($actorId, 'shipment.shipped', 'order', (string) $orderId, ['shipment_id' => $id, 'carrier' => $carrier]);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function confirmReceipt(int $userId, int $orderId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lock($orderId);
            $order = $this->checkout->owned($userId, $orderId);
            $shipment = $this->forOrder($orderId);
            if ($shipment !== null && $shipment['status'] === 'delivered') {
                $this->pdo->commit();
                return;
            }
            if ($shipment === null || $order['status'] !== 'fulfilling') {
                throw new DomainException('订单尚未发货或正在处理退款，不能确认收货。');
            }
            $now = gmdate('c');
            $this->pdo->prepare("UPDATE shipments SET status = 'delivered', received_at = :t, updated_at = :u WHERE id = :id")
                ->execute(['t' => $now, 'u' => $now, 'id' => $shipment['id']]);
            $this->pdo->prepare("UPDATE orders SET status = 'completed', updated_at = :t WHERE id = :id")
                ->execute(['t' => $now, 'id' => $orderId]);
            $this->audit->record($userId, 'shipment.received', 'order', (string) $orderId, ['shipment_id' => (int) $shipment['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function forOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shipments WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function lock(int $orderId): void
    {
        $this->pdo->prepare('UPDATE orders SET updated_at = updated_at WHERE id = :id')->execute(['id' => $orderId]);
    }

    private function assertAdministrator(int $actorId): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT p.name) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id WHERE u.id = :u AND u.status = 'active' AND u.deleted_at IS NULL AND p.name IN ('admin.access','order.manage')");
        $stmt->execute(['u' => $actorId]);
        if ((int) $stmt->fetchColumn() !== 2) {
            throw new DomainException('没有订单发货权限。');
        }
    }
}
