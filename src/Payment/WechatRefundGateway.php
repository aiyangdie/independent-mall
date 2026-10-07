<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Security\AuditLogger;
use PDO;

final class WechatRefundGateway
{
    public function __construct(private PDO $pdo, private Checkout $checkout, private Refunds $refunds,
        private WechatSettings $settings, private WechatTransport $transport, private AuditLogger $audit) {}

    public function submit(int $refundId, int $actorId): string
    {
        [$refund, $order, $payment] = $this->context($refundId);
        if ($refund['status'] === 'succeeded') {
            return 'SUCCESS';
        }
        $data = $this->transport->request('POST', 'v3/refund/domestic/refunds', ['json' => [
            'transaction_id' => $payment['provider_ref'], 'out_refund_no' => $this->number($refund, $order),
            'reason' => mb_substr($refund['reason'], 0, 80),
            'notify_url' => preg_replace('#/notify$#', '/refund-notify', $this->settings->notifyUrl),
            'amount' => ['refund' => (int) $refund['amount_minor'], 'total' => (int) $payment['amount_minor'], 'currency' => 'CNY'],
        ]]);
        $status = $this->accept($refund, $order, $payment, $data);
        $this->audit->record($actorId, 'refund.wechat_submitted', 'refund', (string) $refundId, ['status' => $status]);
        return $status;
    }

    public function sync(int $refundId): string
    {
        [$refund, $order, $payment] = $this->context($refundId);
        if ($refund['status'] === 'succeeded') {
            return 'SUCCESS';
        }
        $data = $this->transport->request('GET', 'v3/refund/domestic/refunds/{out_refund_no}', [
            'out_refund_no' => $this->number($refund, $order),
        ]);
        return $this->accept($refund, $order, $payment, $data);
    }

    public function notify(string $body, array $headers): void
    {
        $event = (new WechatNotification($this->settings))->decrypt($body, $headers);
        $data = $event['resource'];
        if ($event['event_type'] !== 'REFUND.SUCCESS' || ($data['refund_status'] ?? '') !== 'SUCCESS'
            || ($data['mchid'] ?? '') !== $this->settings->merchantId) {
            throw new \DomainException('退款通知商户或状态不匹配。');
        }
        if (preg_match('/_r([0-9]+)$/D', $data['out_refund_no'] ?? '', $match) !== 1) {
            throw new \DomainException('退款通知没有对应申请。');
        }
        [$refund, $order, $payment] = $this->context((int) $match[1]);
        $data['status'] = $data['refund_status'];
        $this->accept($refund, $order, $payment, $data);
    }

    private function context(int $refundId): array
    {
        $refund = $this->refunds->byId($refundId);
        if ($refund['provider'] !== 'wechat' || !in_array($refund['status'], ['approved', 'succeeded'], true)) {
            throw new \DomainException('微信退款申请尚未批准或通道不匹配。');
        }
        $order = $this->checkout->byId((int) $refund['order_id']);
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :p');
        $stmt->execute(['p' => $refund['payment_id']]);
        $payment = $stmt->fetch();
        if (!is_array($payment) || $payment['provider'] !== 'wechat' || $payment['status'] !== 'succeeded'
            || !$payment['provider_ref'] || $payment['currency'] !== 'CNY' || (int) $payment['order_id'] !== (int) $order['id']) {
            throw new \DomainException('找不到对应的微信支付成功记录。');
        }
        return [$refund, $order, $payment];
    }

    private function number(array $refund, array $order): string
    {
        return $order['public_id'] . '_r' . $refund['id'];
    }

    private function accept(array $refund, array $order, array $payment, array $data): string
    {
        $amount = $data['amount'] ?? [];
        if (($data['out_refund_no'] ?? '') !== $this->number($refund, $order)
            || ($data['out_trade_no'] ?? '') !== $order['public_id']
            || ($data['transaction_id'] ?? '') !== $payment['provider_ref']
            || !is_int($amount['total'] ?? null) || $amount['total'] !== (int) $payment['amount_minor']
            || !is_int($amount['refund'] ?? null) || $amount['refund'] !== (int) $refund['amount_minor']
            || (isset($amount['currency']) && $amount['currency'] !== 'CNY')
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['refund_id'] ?? '') !== 1) {
            throw new \DomainException('微信退款结果与申请金额或交易编号不一致。');
        }
        $status = $data['status'] ?? '';
        if ($status === 'SUCCESS') {
            $this->refunds->complete((int) $refund['id'], $data['refund_id']);
        } elseif (!in_array($status, ['PROCESSING', 'CLOSED', 'ABNORMAL'], true)) {
            throw new \DomainException('微信退款状态无效。');
        }
        // PROCESSING/CLOSED/ABNORMAL never mean money has been returned. Preserve the ledger for reconciliation.
        return $status;
    }
}
