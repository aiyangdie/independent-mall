<?php

declare(strict_types=1);

namespace IndependentMall\Checkout;

use DomainException;
use IndependentMall\Security\AuditLogger;
use PDO;

final class Refunds
{
    public function __construct(
        private PDO $pdo,
        private Checkout $checkout,
        private AuditLogger $audit,
    ) {
    }

    public function request(int $userId, int $orderId, int $amountMinor, string $reason, string $idempotencyKey): int
    {
        $order = $this->checkout->owned($userId, $orderId);
        return $this->insert($order, $amountMinor, $reason, $idempotencyKey, $userId);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function insert(array $order, int $amountMinor, string $reason, string $idempotencyKey, ?int $actorId): int
    {
        $dup = $this->pdo->prepare('SELECT id FROM refunds WHERE idempotency_key = :k');
        $dup->execute(['k' => $idempotencyKey]);
        $row = $dup->fetch();
        if (is_array($row)) {
            return (int) $row['id'];
        }
        if (!in_array($order['status'], ['paid', 'completed', 'refund_requested'], true)) {
            throw new DomainException('This order cannot be refunded.');
        }
        if ($amountMinor < 1 || $amountMinor > (int) $order['payable_minor']) {
            throw new DomainException('Refund amount exceeds the paid total.');
        }
        $paid = $this->pdo->prepare("SELECT id FROM payments WHERE order_id = :o AND status = 'succeeded' ORDER BY id DESC");
        $paid->execute(['o' => $order['id']]);
        $payment = $paid->fetch();
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO refunds (order_id, payment_id, amount_minor, status, reason, idempotency_key, created_at, updated_at)
             VALUES (:o, :p, :a, :s, :r, :k, :c, :x)'
        )->execute([
            'o' => $order['id'],
            'p' => is_array($payment) ? $payment['id'] : null,
            'a' => $amountMinor,
            's' => 'requested',
            'r' => $reason,
            'k' => $idempotencyKey,
            'c' => $now,
            'x' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE orders SET status = 'refund_requested', updated_at = :t WHERE id = :id")
            ->execute(['t' => $now, 'id' => $order['id']]);
        $this->audit->record($actorId, 'refund.requested', 'refund', (string) $id);
        return $id;
    }

    public function decide(int $refundId, string $decision, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE id = :id');
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!is_array($refund)) {
            throw new DomainException('Refund not found.');
        }
        if ($refund['status'] !== 'requested') {
            if ($refund['status'] === $decision || ($decision === 'approved' && $refund['status'] === 'succeeded')) {
                return;
            }
            throw new DomainException('Refund already decided.');
        }
        $now = gmdate('c');
        if ($decision === 'rejected') {
            $this->pdo->prepare("UPDATE refunds SET status = 'rejected', updated_at = :t WHERE id = :id")
                ->execute(['t' => $now, 'id' => $refundId]);
            $this->pdo->prepare("UPDATE orders SET status = 'completed', updated_at = :t WHERE id = :id")
                ->execute(['t' => $now, 'id' => $refund['order_id']]);
            $this->audit->record($actorId, 'refund.rejected', 'refund', (string) $refundId);
            return;
        }
        if ($decision !== 'approved') {
            throw new DomainException('Unknown refund decision.');
        }
        $this->pdo->prepare("UPDATE refunds SET status = 'succeeded', updated_at = :t WHERE id = :id")
            ->execute(['t' => $now, 'id' => $refundId]);
        $this->pdo->prepare("UPDATE orders SET status = 'refunded', updated_at = :t WHERE id = :id")
            ->execute(['t' => $now, 'id' => $refund['order_id']]);
        $this->audit->record($actorId, 'refund.succeeded', 'refund', (string) $refundId, ['adapter' => 'fake']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM refunds ORDER BY id DESC')->fetchAll() ?: [];
    }
}
