<?php

declare(strict_types=1);

namespace IndependentMall\Checkout;

use DomainException;
use IndependentMall\Security\AuditLogger;
use PDO;

final class Refunds
{
    public function __construct(private PDO $pdo, private Checkout $checkout, private AuditLogger $audit)
    {
    }

    public function request(int $userId, int $orderId, int $amountMinor, string $reason, string $idempotencyKey): int
    {
        $idempotencyKey = trim($idempotencyKey);
        $reason = trim($reason);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160 || $reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('请填写有效的退款原因与申请编号。');
        }
        $this->pdo->beginTransaction();
        try {
            $this->lockOrder($orderId);
            $order = $this->checkout->owned($userId, $orderId);
            $dup = $this->pdo->prepare('SELECT * FROM refunds WHERE idempotency_key = :k');
            $dup->execute(['k' => $idempotencyKey]);
            $found = $dup->fetch();
            if (is_array($found)) {
                if ((int) $found['order_id'] !== $orderId || (int) $found['amount_minor'] !== $amountMinor || $found['reason'] !== $reason) {
                    throw new DomainException('申请编号已使用，不能变更退款内容。');
                }
                $this->pdo->commit();
                return (int) $found['id'];
            }
            if (!in_array($order['status'], ['paid', 'fulfilling', 'completed', 'refund_requested'], true)) {
                throw new DomainException('此订单当前不能申请退款。');
            }
            $paid = $this->pdo->prepare("SELECT * FROM payments WHERE order_id = :o AND status = 'succeeded' ORDER BY id DESC LIMIT 1");
            $paid->execute(['o' => $orderId]);
            $payment = $paid->fetch();
            if (!is_array($payment) || $payment['currency'] !== $order['currency']) {
                throw new DomainException('订单没有可退款的成功支付记录。');
            }
            $remaining = min((int) $payment['amount_minor'], (int) $order['payable_minor']) - $this->reservedAmount($orderId);
            if ($amountMinor < 1 || $amountMinor > $remaining) {
                throw new DomainException('累计退款申请不能超过剩余可退金额。');
            }
            $before = $order['status'] === 'refund_requested' ? $this->resumeStatus($orderId) : $order['status'];
            $now = gmdate('c');
            $this->pdo->prepare('INSERT INTO refunds (order_id, payment_id, amount_minor, status, reason, idempotency_key, created_at, updated_at, provider, order_status_before) VALUES (:o, :p, :a, :s, :r, :k, :c, :x, :provider, :before)')
                ->execute(['o' => $orderId, 'p' => $payment['id'], 'a' => $amountMinor, 's' => 'requested', 'r' => $reason, 'k' => $idempotencyKey, 'c' => $now, 'x' => $now, 'provider' => $payment['provider'], 'before' => $before]);
            $id = (int) $this->pdo->lastInsertId();
            $this->updateOrderStatus($orderId, 'refund_requested');
            $this->audit->record($userId, 'refund.requested', 'refund', (string) $id, ['provider' => (string) $payment['provider'], 'amount_minor' => $amountMinor]);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** Only the authenticated administrator controller calls this method. */
    public function decide(int $refundId, string $decision, ?int $actorId): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainException('退款审核操作无效。');
        }
        $this->pdo->beginTransaction();
        try {
            $refund = $this->byId($refundId);
            $this->lockOrder((int) $refund['order_id']);
            $refund = $this->byId($refundId);
            if ($refund['status'] !== 'requested') {
                if ($refund['status'] === $decision || ($decision === 'approved' && in_array($refund['status'], ['approved', 'succeeded'], true))) {
                    $this->pdo->commit();
                    return;
                }
                throw new DomainException('退款申请已处理，不能重复变更。');
            }
            // Fake is a simulation. Real channels stay approved until a verified result.
            $status = $decision === 'rejected' ? 'rejected' : ($refund['provider'] === 'fake' ? 'succeeded' : 'approved');
            $this->pdo->prepare('UPDATE refunds SET status = :s, updated_at = :t WHERE id = :id')
                ->execute(['s' => $status, 't' => gmdate('c'), 'id' => $refundId]);
            $this->reconcileOrder((int) $refund['order_id']);
            $this->audit->record($actorId, 'refund.' . $status, 'refund', (string) $refundId, ['adapter' => (string) $refund['provider']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** Called only after a provider adapter authenticates a successful refund result. */
    public function complete(int $refundId, string $providerRef): void
    {
        if (trim($providerRef) === '') {
            throw new DomainException('退款交易编号不能为空。');
        }
        $this->pdo->beginTransaction();
        try {
            $refund = $this->byId($refundId);
            $this->lockOrder((int) $refund['order_id']);
            $refund = $this->byId($refundId);
            if ($refund['status'] === 'succeeded') {
                if ($refund['provider_ref'] !== $providerRef) {
                    throw new DomainException('退款交易编号不一致。');
                }
                $this->pdo->commit();
                return;
            }
            if ($refund['status'] !== 'approved' || $refund['provider'] === 'fake') {
                throw new DomainException('退款未批准或退款通道不匹配。');
            }
            $this->pdo->prepare("UPDATE refunds SET status = 'succeeded', provider_ref = :r, updated_at = :t WHERE id = :id")
                ->execute(['r' => $providerRef, 't' => gmdate('c'), 'id' => $refundId]);
            $this->reconcileOrder((int) $refund['order_id']);
            $this->audit->record(null, 'refund.succeeded', 'refund', (string) $refundId, ['adapter' => (string) $refund['provider']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function remaining(int $orderId): int
    {
        $order = $this->checkout->byId($orderId);
        return max(0, (int) $order['payable_minor'] - $this->reservedAmount($orderId));
    }

    /** @return array<string, mixed> */
    public function byId(int $refundId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE id = :id');
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!is_array($refund)) {
            throw new DomainException('退款申请不存在。');
        }
        return $refund;
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE order_id = :o ORDER BY id DESC');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT r.*, o.public_id, o.status AS order_status FROM refunds r JOIN orders o ON o.id = r.order_id ORDER BY r.id DESC')->fetchAll() ?: [];
    }

    private function reservedAmount(int $orderId): int
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount_minor), 0) FROM refunds WHERE order_id = :o AND status IN ('requested','approved','succeeded')");
        $stmt->execute(['o' => $orderId]);
        return (int) $stmt->fetchColumn();
    }

    private function resumeStatus(int $orderId): string
    {
        $stmt = $this->pdo->prepare('SELECT order_status_before FROM refunds WHERE order_id = :o ORDER BY id LIMIT 1');
        $stmt->execute(['o' => $orderId]);
        $base = (string) $stmt->fetchColumn();
        $ship = $this->pdo->prepare('SELECT status FROM shipments WHERE order_id = :o');
        $ship->execute(['o' => $orderId]);
        $status = $ship->fetchColumn();
        if ($status === 'delivered') {
            return 'completed';
        }
        if ($status === 'shipped') {
            return 'fulfilling';
        }
        return in_array($base, ['paid', 'completed'], true) ? $base : 'paid';
    }

    private function reconcileOrder(int $orderId): void
    {
        $order = $this->checkout->byId($orderId);
        $totals = $this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status = 'succeeded' THEN amount_minor ELSE 0 END), 0) AS refunded, COUNT(CASE WHEN status IN ('requested','approved') THEN 1 END) AS pending FROM refunds WHERE order_id = :o");
        $totals->execute(['o' => $orderId]);
        $row = $totals->fetch();
        $full = (int) $row['refunded'] >= (int) $order['payable_minor'];
        $this->updateOrderStatus($orderId, $full ? 'refunded' : ((int) $row['pending'] > 0 ? 'refund_requested' : $this->resumeStatus($orderId)));
        if ($full) {
            $this->pdo->prepare('UPDATE digital_deliveries SET revoked = 1 WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :o)')->execute(['o' => $orderId]);
        }
        // A refund does not prove a physical return. Never blindly restock sold goods.
    }

    private function updateOrderStatus(int $orderId, string $status): void
    {
        $this->pdo->prepare('UPDATE orders SET status = :s, updated_at = :t WHERE id = :id')->execute(['s' => $status, 't' => gmdate('c'), 'id' => $orderId]);
    }

    private function lockOrder(int $orderId): void
    {
        $this->pdo->prepare('UPDATE orders SET updated_at = updated_at WHERE id = :id')->execute(['id' => $orderId]);
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
