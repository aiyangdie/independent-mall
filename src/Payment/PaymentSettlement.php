<?php

declare(strict_types=1);

namespace IndependentMall\Payment;

use DomainException;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Security\AuditLogger;
use PDO;

/** Called only by an adapter after authenticating its provider notification. */
final class PaymentSettlement
{
    public function __construct(
        private PDO $pdo,
        private Checkout $checkout,
        private ProductCatalog $catalog,
        private DigitalFulfillment $digital,
        private AuditLogger $audit,
    ) {
    }

    /** @return list<string> Newly issued digital delivery tokens, never logged. */
    public function settle(int $paymentId, string $providerRef, int $amountMinor, string $currency): array
    {
        if (trim($providerRef) === '' || $amountMinor < 1) {
            throw new DomainException('支付通知缺少有效的交易编号或金额。');
        }
        $this->pdo->beginTransaction();
        try {
            // Claim the payment write lock before examining order state.
            $this->pdo->prepare('UPDATE payments SET updated_at = updated_at WHERE id = :id')
                ->execute(['id' => $paymentId]);
            $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
            $stmt->execute(['id' => $paymentId]);
            $payment = $stmt->fetch();
            if (!is_array($payment)) {
                throw new DomainException('支付记录不存在。');
            }
            $order = $this->checkout->byId((int) $payment['order_id']);
            if ($amountMinor !== (int) $payment['amount_minor'] || $amountMinor !== (int) $order['payable_minor']
                || $currency !== $payment['currency'] || $currency !== $order['currency']) {
                throw new DomainException('支付金额或币种与订单不一致。');
            }
            if ($payment['status'] === 'succeeded') {
                if ($payment['provider_ref'] !== $providerRef) {
                    throw new DomainException('重复支付通知的交易编号不一致。');
                }
                $this->pdo->commit();
                return [];
            }
            // The conditional order write serializes successful payments and cancellation.
            $claim = $this->pdo->prepare("UPDATE orders SET status = 'paid', updated_at = :t WHERE id = :id AND status = 'pending_payment'");
            $claim->execute(['t' => gmdate('c'), 'id' => $order['id']]);
            if ($claim->rowCount() !== 1) {
                throw new DomainException('订单不是待付款状态，请人工核对到账情况。');
            }
            if (!in_array($payment['status'], ['created', 'failed'], true)) {
                throw new DomainException('此支付记录不能结算。');
            }
            $items = $this->checkout->items((int) $order['id']);
            $physical = false;
            foreach ($items as $item) {
                $physical = $physical || $item['product_type'] === 'physical';
                $this->catalog->applyMovement(
                    (int) $item['variant_id'], -(int) $item['qty'], -(int) $item['qty'],
                    'sale', 'sale:' . $order['public_id'] . ':v' . $item['variant_id'],
                );
            }
            $this->pdo->prepare("UPDATE payments SET status = 'succeeded', provider_ref = :r, updated_at = :t WHERE id = :id")
                ->execute(['r' => $providerRef, 't' => gmdate('c'), 'id' => $paymentId]);
            $tokens = $this->digital->fulfillPaidOrder((int) $order['id']);
            if (!$physical) {
                $this->pdo->prepare("UPDATE orders SET status = 'completed', updated_at = :t WHERE id = :id")
                    ->execute(['t' => gmdate('c'), 'id' => $order['id']]);
            }
            $this->audit->record((int) $order['user_id'], 'payment.succeeded', 'order', (string) $order['id'], [
                'adapter' => (string) $payment['provider'], 'payment_id' => $paymentId,
            ]);
            $this->pdo->commit();
            return $tokens;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
