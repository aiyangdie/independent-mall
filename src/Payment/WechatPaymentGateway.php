<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

use IndependentMall\Checkout\Checkout;
use IndependentMall\Security\AuditLogger;
use PDO;

final class WechatPaymentGateway
{
    public function __construct(
        private PDO $pdo, private Checkout $checkout, private PaymentSettlement $settlement,
        private WechatSettings $settings, private WechatTransport $transport, private AuditLogger $audit,
    ) {}

    public function native(int $userId, int $orderId): string
    {
        $order = $this->checkout->owned($userId, $orderId);
        if ($order['status'] !== 'pending_payment' || $order['currency'] !== 'CNY' || (int) $order['payable_minor'] < 1) {
            throw new \DomainException('该订单无法发起微信支付。');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE orders SET updated_at = updated_at WHERE id = :o')->execute(['o' => $orderId]);
            $order = $this->checkout->owned($userId, $orderId);
            if ($order['status'] !== 'pending_payment') {
                throw new \DomainException('该订单不是待付款状态。');
            }
            $row = $this->nativeRecord($orderId);
            if ($row === null) {
                $now = gmdate('c');
                $this->pdo->prepare('INSERT INTO payments (order_id, provider, amount_minor, currency, status, idempotency_key, created_at, updated_at)
                    VALUES (:o, :p, :a, :c, :s, :k, :t, :x)')->execute([
                    'o' => $orderId, 'p' => 'wechat', 'a' => $order['payable_minor'], 'c' => 'CNY',
                    's' => 'created', 'k' => 'wechat:order:' . $orderId, 't' => $now, 'x' => $now,
                ]);
                $this->pdo->prepare('INSERT INTO wechat_native_orders (order_id, payment_id, expires_at, created_at) VALUES (:o, :p, :e, :c)')
                    ->execute(['o' => $orderId, 'p' => $this->pdo->lastInsertId(), 'e' => gmdate('c', time() + 7200), 'c' => $now]);
                $row = $this->nativeRecord($orderId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        if ($row['expires_at'] <= gmdate('c')) {
            throw new \DomainException('付款二维码已过期，请取消订单后重新下单。');
        }
        if (!empty($row['code_url'])) {
            return $row['code_url'];
        }
        // Same out_trade_no on retries: an ambiguous timeout must not create a second charge.
        $data = $this->transport->request('POST', 'v3/pay/transactions/native', ['json' => [
            'appid' => $this->settings->appId, 'mchid' => $this->settings->merchantId,
            'description' => '商城订单 ' . $order['public_id'], 'out_trade_no' => $order['public_id'],
            'notify_url' => $this->settings->notifyUrl, 'time_expire' => $row['expires_at'],
            'amount' => ['total' => (int) $order['payable_minor'], 'currency' => 'CNY'],
        ]]);
        $code = $data['code_url'] ?? '';
        if (!is_string($code) || !str_starts_with($code, 'weixin://wxpay/bizpayurl?') || strlen($code) > 2048) {
            throw new \DomainException('微信支付未返回有效二维码，请稍后重试。');
        }
        $this->pdo->prepare('UPDATE wechat_native_orders SET code_url = :c WHERE order_id = :o')
            ->execute(['c' => $code, 'o' => $orderId]);
        $this->audit->record($userId, 'payment.wechat_created', 'order', (string) $orderId);
        return $code;
    }

    /** Query response authenticity is enforced by the official SDK transport. */
    public function sync(int $userId, int $orderId): array
    {
        $order = $this->checkout->owned($userId, $orderId);
        if ($this->nativeRecord($orderId) === null) {
            throw new \DomainException('该订单尚未发起微信支付。');
        }
        $data = $this->query($order['public_id']);
        return ($data['trade_state'] ?? '') === 'SUCCESS' ? $this->acceptTransaction($data) : [];
    }

    public function notify(string $body, array $headers): array
    {
        $event = (new WechatNotification($this->settings))->decrypt($body, $headers);
        if ($event['event_type'] !== 'TRANSACTION.SUCCESS') {
            throw new \DomainException('不支持的支付通知类型。');
        }
        return $this->acceptTransaction($event['resource']);
    }

    public function hasNative(int $orderId): bool
    {
        return $this->nativeRecord($orderId) !== null;
    }

    /** Close upstream before local cancellation releases reserved stock. */
    public function closeBeforeCancel(int $userId, int $orderId): void
    {
        $order = $this->checkout->owned($userId, $orderId);
        if (!$this->hasNative($orderId) || $order['status'] !== 'pending_payment') {
            return;
        }
        $data = $this->query($order['public_id']);
        if (($data['trade_state'] ?? '') === 'SUCCESS') {
            $this->acceptTransaction($data);
            throw new \DomainException('订单已付款，不能取消；请使用退款申请。');
        }
        if (($data['trade_state'] ?? '') !== 'CLOSED') {
            $this->transport->request('POST', 'v3/pay/transactions/out-trade-no/{out_trade_no}/close', [
                'out_trade_no' => $order['public_id'], 'json' => ['mchid' => $this->settings->merchantId],
            ]);
        }
    }

    private function query(string $publicId): array
    {
        $data = $this->transport->request('GET', 'v3/pay/transactions/out-trade-no/{out_trade_no}', [
            'out_trade_no' => $publicId, 'query' => ['mchid' => $this->settings->merchantId],
        ]);
        if (($data['out_trade_no'] ?? '') !== $publicId || ($data['mchid'] ?? '') !== $this->settings->merchantId
            || ($data['appid'] ?? '') !== $this->settings->appId) {
            throw new \DomainException('微信支付查询结果与订单或商户不一致。');
        }
        return $data;
    }

    private function acceptTransaction(array $data): array
    {
        $amount = $data['amount'] ?? [];
        if (($data['appid'] ?? '') !== $this->settings->appId || ($data['mchid'] ?? '') !== $this->settings->merchantId
            || ($data['trade_state'] ?? '') !== 'SUCCESS' || ($data['trade_type'] ?? '') !== 'NATIVE'
            || !is_int($amount['total'] ?? null) || ($amount['currency'] ?? '') !== 'CNY'
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['transaction_id'] ?? '') !== 1) {
            throw new \DomainException('微信支付通知商户、金额或交易状态不匹配。');
        }
        $order = $this->checkout->byPublicId($data['out_trade_no'] ?? '');
        $native = $this->nativeRecord((int) $order['id']);
        if ($native === null) {
            throw new \DomainException('微信支付通知没有对应的付款请求。');
        }
        return $this->settlement->settle((int) $native['payment_id'], $data['transaction_id'], $amount['total'], 'CNY');
    }

    private function nativeRecord(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wechat_native_orders WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
