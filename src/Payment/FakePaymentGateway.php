<?php

declare(strict_types=1);

namespace IndependentMall\Payment;

use DomainException;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Security\AuditLogger;
use PDO;

final class FakePaymentGateway
{
    public function __construct(
        private PDO $pdo,
        private Checkout $checkout,
        private ProductCatalog $catalog,
        private DigitalFulfillment $digital,
        private AuditLogger $audit,
        private string $secret,
    ) {
    }

    public function sign(array $fields): string
    {
        ksort($fields);
        return hash_hmac('sha256', http_build_query($fields), $this->secret);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $orderId, string $idempotencyKey): array
    {
        $order = $this->checkout->byId($orderId);
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE idempotency_key = :k');
        $stmt->execute(['k' => $idempotencyKey]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            return $existing;
        }
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO payments (order_id, provider, amount_minor, currency, status, idempotency_key, created_at, updated_at)
             VALUES (:o, :p, :a, :c, :s, :k, :t, :u)'
        )->execute([
            'o' => $orderId,
            'p' => 'fake',
            'a' => $order['payable_minor'],
            'c' => $order['currency'],
            's' => 'created',
            'k' => $idempotencyKey,
            't' => $now,
            'u' => $now,
        ]);
        return $this->byId((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string, string> $payload
     * @return list<string>
     */
    public function notify(array $payload): array
    {
        $sig = $payload['signature'] ?? '';
        unset($payload['signature']);
        if (!hash_equals($this->sign($payload), $sig)) {
            throw new DomainException('Invalid payment signature.');
        }
        $order = $this->checkout->byPublicId($payload['merchant_order'] ?? '');
        $amount = (int) ($payload['amount_minor'] ?? 0);
        $currency = $payload['currency'] ?? '';
        if ($amount !== (int) $order['payable_minor'] || $currency !== $order['currency']) {
            throw new DomainException('Payment amount or currency mismatch.');
        }
        $payment = $this->create((int) $order['id'], 'notify:' . ($payload['provider_ref'] ?? ''));
        $redacted = $payload;
        unset($redacted['card'], $redacted['secret']);
        $this->pdo->prepare('INSERT INTO payment_attempts (payment_id, payload_redacted, created_at) VALUES (:p, :b, :t)')
            ->execute(['p' => $payment['id'], 'b' => json_encode($redacted), 't' => gmdate('c')]);

        $outcome = $payload['outcome'] ?? 'failed';
        if ($outcome !== 'succeeded') {
            $this->pdo->prepare("UPDATE payments SET status = 'failed', provider_ref = :r, updated_at = :t WHERE id = :id")
                ->execute(['r' => $payload['provider_ref'] ?? '', 't' => gmdate('c'), 'id' => $payment['id']]);
            return [];
        }
        if ($payment['status'] === 'succeeded' || $order['status'] === 'paid' || $order['status'] === 'completed') {
            return [];
        }
        if ($order['status'] !== 'pending_payment') {
            throw new DomainException('Order is not awaiting payment.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE payments SET status = 'succeeded', provider_ref = :r, updated_at = :t WHERE id = :id")
                ->execute(['r' => $payload['provider_ref'] ?? '', 't' => gmdate('c'), 'id' => $payment['id']]);
            $items = $this->checkout->items((int) $order['id']);
            foreach ($items as $item) {
                $this->catalog->applyMovement(
                    (int) $item['variant_id'],
                    -(int) $item['qty'],
                    -(int) $item['qty'],
                    'sale',
                    'sale:' . $order['public_id'] . ':v' . $item['variant_id'],
                );
            }
            $this->pdo->prepare("UPDATE orders SET status = 'paid', updated_at = :t WHERE id = :id")
                ->execute(['t' => gmdate('c'), 'id' => $order['id']]);
            $issued = $this->digital->fulfillPaidOrder((int) $order['id']);
            $this->pdo->prepare("UPDATE orders SET status = 'completed', updated_at = :t WHERE id = :id")
                ->execute(['t' => gmdate('c'), 'id' => $order['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->record((int) $order['user_id'], 'payment.succeeded', 'order', (string) $order['id']);
        return $issued;
    }

    /**
     * @return array<string, mixed>
     */
    public function byId(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new DomainException('Payment not found.');
        }
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = :o ORDER BY id');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT p.*, o.public_id, o.status AS order_status
             FROM payments p JOIN orders o ON o.id = p.order_id
             ORDER BY p.id DESC'
        )->fetchAll() ?: [];
    }
}
