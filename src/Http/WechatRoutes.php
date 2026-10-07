<?php
declare(strict_types=1);
namespace IndependentMall\Http;

use IndependentMall\Account\AddressBook;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Checkout\Cart;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Kernel;
use IndependentMall\Payment\PaymentQr;
use IndependentMall\Payment\PaymentSettlement;
use IndependentMall\Payment\WechatPaymentGateway;
use IndependentMall\Payment\WechatRefundGateway;
use IndependentMall\Payment\WechatSdkTransport;
use IndependentMall\Payment\WechatSettings;
use IndependentMall\Security\AuditLogger;

final class WechatRoutes
{
    public static function register(Kernel $app): void
    {
        $app->router->add('POST', '#^/orders/(\d+)/pay-wechat$#', function (Request $req, array $m) use ($app) {
            if ($deny = self::customerGuard($app, $req)) { return $deny; }
            try {
                self::payment($app)->native((int) $app->auth->user()['id'], (int) $m[1]);
                return $app->page('store/wechat-pay', [
                    'title' => '微信扫码支付', 'order' => self::checkout($app)->owned((int) $app->auth->user()['id'], (int) $m[1]),
                    'csrf' => $app->csrf->field(), 'qr_src' => '/orders/' . $m[1] . '/wechat-qr.svg',
                ]);
            } catch (\DomainException $e) {
                return $app->page('store/payment-error', ['title' => '暂时无法付款', 'error' => $e->getMessage()], 422);
            }
        });
        $app->router->add('GET', '#^/orders/(\d+)/wechat-qr\.svg$#', function (Request $req, array $m) use ($app) {
            if ($app->auth->user() === null) { return Response::html('请先登录。', 401); }
            try {
                $order = self::checkout($app)->owned((int) $app->auth->user()['id'], (int) $m[1]);
                if ($order['status'] !== 'pending_payment') { return Response::html('订单已不处于待付款状态。', 409); }
                $stmt = $app->pdo->prepare('SELECT code_url FROM wechat_native_orders WHERE order_id = :o AND expires_at > :t');
                $stmt->execute(['o' => $order['id'], 't' => gmdate('c')]);
                $code = $stmt->fetchColumn();
                if (!is_string($code)) { return Response::html('二维码已过期或不存在。', 404); }
                return new Response(PaymentQr::svg($code), 200, ['Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
            } catch (\DomainException) { return Response::html('二维码不可用。', 404); }
        });
        $app->router->add('POST', '#^/orders/(\d+)/wechat-sync$#', function (Request $req, array $m) use ($app) {
            if ($deny = self::customerGuard($app, $req)) { return $deny; }
            try {
                $tokens = self::payment($app)->sync((int) $app->auth->user()['id'], (int) $m[1]);
                if ($tokens !== []) { $_SESSION['flash_delivery_tokens'] = $tokens; }
                return Response::redirect('/orders/' . $m[1] . '/result');
            } catch (\DomainException $e) {
                return $app->page('store/payment-error', ['title' => '付款状态查询失败', 'error' => $e->getMessage()], 422);
            }
        });
        foreach (['notify', 'refund-notify'] as $kind) {
            $app->router->add('POST', '/payments/wechat/' . $kind, function (Request $req) use ($app, $kind) {
                $headers = [];
                foreach (['Wechatpay-Signature', 'Wechatpay-Timestamp', 'Wechatpay-Serial', 'Wechatpay-Nonce'] as $header) {
                    $headers[$header] = $req->header($header);
                }
                try {
                    if ($kind === 'notify') { self::payment($app)->notify($req->rawBody(), $headers); }
                    else { self::refund($app)->notify($req->rawBody(), $headers); }
                    return new Response('', 204, ['Cache-Control' => 'no-store']);
                } catch (\DomainException) {
                    return Response::json(['code' => 'FAIL', 'message' => '通知验证或业务核对失败。'], 400);
                } catch (\Throwable $e) {
                    error_log('Wechat notification failed: ' . get_class($e));
                    return Response::json(['code' => 'FAIL', 'message' => '服务暂不可用，请重试。'], 500);
                }
            });
        }
        foreach (['wechat-submit', 'wechat-sync'] as $action) {
            $app->router->add('POST', '#^/admin/refunds/(\d+)/' . $action . '$#', function (Request $req, array $m) use ($app, $action) {
                if (!$app->auth->can('admin.access') && !$app->auth->can('order.manage')) {
                    return Response::redirect('/admin/login');
                }
                if (!$app->csrf->assert($req)) { return Response::html('表单已过期，请刷新后重试。', 400); }
                try {
                    $status = $action === 'wechat-submit'
                        ? self::refund($app)->submit((int) $m[1], (int) $app->auth->user()['id'])
                        : self::refund($app)->sync((int) $m[1]);
                    $notice = match ($status) {
                        'SUCCESS' => '微信已确认退款成功。', 'PROCESSING' => '微信正在处理退款，请稍后再次查询。',
                        default => '微信退款尚未成功，请到商户平台核对并人工处理。',
                    };
                    return $app->page('admin/refunds', ['title' => '退款管理', 'refunds' => self::refunds($app)->all(),
                        'csrf' => $app->csrf->field(), 'notice' => $notice, 'user' => $app->auth->user()]);
                } catch (\DomainException $e) {
                    return $app->page('store/payment-error', ['title' => '退款处理失败', 'error' => $e->getMessage()], 422);
                }
            });
        }
    }

    private static function customerGuard(Kernel $app, Request $req): ?Response
    {
        if ($app->auth->user() === null) { return Response::redirect('/login'); }
        if (!$app->csrf->assert($req)) { return Response::html('表单已过期，请刷新后重试。', 400); }
        return null;
    }

    public static function payment(Kernel $app): WechatPaymentGateway
    {
        $settings = WechatSettings::fromConfig($app->config);
        $checkout = self::checkout($app);
        $audit = new AuditLogger($app->pdo);
        $catalog = self::catalog($app);
        $digital = new DigitalFulfillment($app->pdo, $checkout, $audit, $app->config->get('APP_KEY'));
        return new WechatPaymentGateway($app->pdo, $checkout, new PaymentSettlement($app->pdo, $checkout, $catalog, $digital, $audit),
            $settings, new WechatSdkTransport($settings), $audit);
    }

    private static function refund(Kernel $app): WechatRefundGateway
    {
        $settings = WechatSettings::fromConfig($app->config);
        return new WechatRefundGateway($app->pdo, self::checkout($app), self::refunds($app), $settings,
            new WechatSdkTransport($settings), new AuditLogger($app->pdo));
    }

    private static function catalog(Kernel $app): ProductCatalog
    {
        return new ProductCatalog($app->pdo, new ProhibitedGoodsPolicy($app->pdo), new AuditLogger($app->pdo));
    }

    private static function checkout(Kernel $app): Checkout
    {
        $catalog = self::catalog($app);
        return new Checkout($app->pdo, new Cart($app->pdo, $catalog), $catalog, new AddressBook($app->pdo), new AuditLogger($app->pdo));
    }

    private static function refunds(Kernel $app): Refunds
    {
        return new Refunds($app->pdo, self::checkout($app), new AuditLogger($app->pdo));
    }
}
