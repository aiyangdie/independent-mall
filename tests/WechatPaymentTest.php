<?php
declare(strict_types=1);
namespace IndependentMall\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use IndependentMall\Account\AddressBook;
use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Catalog\ProductCatalog;
use IndependentMall\Catalog\ProhibitedGoodsPolicy;
use IndependentMall\Checkout\Cart;
use IndependentMall\Checkout\Checkout;
use IndependentMall\Checkout\Refunds;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Fulfillment\DigitalFulfillment;
use IndependentMall\Mail\ArrayMailer;
use IndependentMall\Payment\PaymentMode;
use IndependentMall\Payment\PaymentQr;
use IndependentMall\Payment\PaymentSettlement;
use IndependentMall\Payment\WechatNotification;
use IndependentMall\Payment\WechatPaymentGateway;
use IndependentMall\Payment\WechatSdkTransport;
use IndependentMall\Payment\WechatSettings;
use IndependentMall\Payment\WechatRefundGateway;
use IndependentMall\Security\AuditLogger;
use PHPUnit\Framework\TestCase;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

final class WechatPaymentTest extends TestCase
{
    private static array $keys = [];
    private WechatSettings $settings;
    private \PDO $pdo;
    private Checkout $checkout;
    private PaymentSettlement $settlement;
    private AuditLogger $audit;
    private int $user;
    private int $orderId;
    private string $publicId;

    protected function setUp(): void
    {
        if (self::$keys === []) {
            foreach (['merchant', 'platform'] as $name) {
                $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
                    'config' => dirname(__DIR__) . '/config/openssl.cnf'];
                $key = openssl_pkey_new($options);
                $this->assertNotFalse($key);
                openssl_pkey_export($key, $private, null, $options);
                self::$keys[$name] = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
            }
        }
        $this->settings = new WechatSettings('wx1234567890abcdef', '1900000001', 'AABBCCDD00112233',
            self::$keys['merchant']['private'], 'PUB_KEY_ID_TEST', self::$keys['platform']['public'],
            str_repeat('a', 32), 'https://shop.example.invalid/payments/wechat/notify');
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        $this->audit = new AuditLogger($this->pdo);
        $hasher = new PasswordHasher();
        $auth = new SessionAuth($this->pdo, $hasher, new RateLimiter($this->pdo), $this->audit, 'test');
        $this->user = (new CustomerAccounts($this->pdo, $hasher, new ArrayMailer(), $this->audit, $auth))
            ->register('wx@example.invalid', 'wxuser', 'correct-horse-1');
        $catalog = new ProductCatalog($this->pdo, new ProhibitedGoodsPolicy($this->pdo), $this->audit);
        $p = $catalog->createDraft(2, 'digital', '正版练习册', 'wx-sheet', '', '', 'WX-1', '标准', 1800, 5, 1);
        $catalog->publish($p['product_id'], 1);
        $catalog->attachDigitalAsset($p['product_id'], '练习册', 'worksheet');
        $cart = new Cart($this->pdo, $catalog);
        $cart->add($this->user, $p['variant_id'], 1);
        $this->checkout = new Checkout($this->pdo, $cart, $catalog, new AddressBook($this->pdo), $this->audit);
        $order = $this->checkout->place($this->user, null, 'wx-order');
        $this->orderId = (int) $order['id'];
        $this->publicId = $order['public_id'];
        $digital = new DigitalFulfillment($this->pdo, $this->checkout, $this->audit, 'test');
        $this->settlement = new PaymentSettlement($this->pdo, $this->checkout, $catalog, $digital, $this->audit);
    }

    public function testNativeUsesSignedOfficialApiAndWebhookIsIdempotent(): void
    {
        $mock = new MockHandler([function ($request) {
            $this->assertSame('https://api.mch.weixin.qq.com/v3/pay/transactions/native', (string) $request->getUri());
            $json = json_decode((string) $request->getBody(), true);
            $this->assertSame(1800, $json['amount']['total']);
            $this->assertSame($this->publicId, $json['out_trade_no']);
            $this->assertSame($this->settings->notifyUrl, $json['notify_url']);
            $authorization = $request->getHeaderLine('Authorization');
            preg_match('/nonce_str="([^"]+)"/', $authorization, $nonce);
            preg_match('/timestamp="([^"]+)"/', $authorization, $timestamp);
            preg_match('/signature="([^"]+)"/', $authorization, $signature);
            $this->assertTrue(Rsa::verify(Formatter::request('POST', $request->getRequestTarget(),
                $timestamp[1], $nonce[1], (string) $request->getBody()), $signature[1],
                Rsa::from(self::$keys['merchant']['public'], Rsa::KEY_TYPE_PUBLIC)));
            return $this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=test123']);
        }]);
        $gateway = $this->gateway($mock);
        $code = $gateway->native($this->user, $this->orderId);
        $this->assertSame($code, $gateway->native($this->user, $this->orderId));
        $this->assertSame(0, $mock->count(), 'cached retry does not initiate another charge');
        $this->assertStringContainsString('<svg', PaymentQr::svg($code));
        [$body, $headers] = $this->notification($this->transaction());
        $this->assertCount(1, $gateway->notify($body, $headers));
        $this->assertSame([], $gateway->notify($body, $headers));
        $this->assertSame('completed', $this->checkout->byId($this->orderId)['status']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE reason='sale'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM digital_deliveries')->fetchColumn());
    }

    public function testTamperedOfficialResponseIsNotAccepted(): void
    {
        $valid = $this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=real']);
        $bad = new Response(200, $valid->getHeaders(), '{"code_url":"weixin://wxpay/bizpayurl?pr=tampered"}');
        $gateway = $this->gateway(new MockHandler([$bad]));
        $this->expectException(\DomainException::class);
        $gateway->native($this->user, $this->orderId);
    }

    public function testNotificationRejectsForgeryReplayAndWrongKeyId(): void
    {
        [$body, $headers] = $this->notification($this->transaction());
        $verifier = new WechatNotification($this->settings);
        foreach (['body', 'timestamp', 'serial', 'signature', 'ciphertext'] as $case) {
            $b = $body;
            $h = $headers;
            if ($case === 'body') { $b .= ' '; }
            if ($case === 'timestamp') { $h['Wechatpay-Timestamp'] = (string) (time() - 301); }
            if ($case === 'serial') { $h['Wechatpay-Serial'] = 'PUB_KEY_ID_ATTACKER'; }
            if ($case === 'signature') { $h['Wechatpay-Signature'] = 'bad'; }
            if ($case === 'ciphertext') {
                $event = json_decode($body, true);
                $event['resource']['ciphertext'] = base64_encode('forged');
                $b = json_encode($event);
                $h = $this->headers($b);
            }
            try {
                $verifier->decrypt($b, $h);
                $this->fail('accepted ' . $case);
            } catch (\DomainException $e) {
                $this->assertSame('微信支付通知验证失败。', $e->getMessage());
            }
        }
    }

    public function testSignedWrongAmountOrMerchantNeverPaysOrder(): void
    {
        $gateway = $this->gateway(new MockHandler([$this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=x'])]));
        $gateway->native($this->user, $this->orderId);
        foreach (['amount', 'mchid', 'appid'] as $field) {
            $tx = $this->transaction();
            if ($field === 'amount') { $tx['amount']['total'] = 1; } else { $tx[$field] = 'wrong'; }
            [$body, $headers] = $this->notification($tx);
            try {
                $gateway->notify($body, $headers);
                $this->fail('accepted wrong ' . $field);
            } catch (\DomainException) {
                $this->assertSame('pending_payment', $this->checkout->byId($this->orderId)['status']);
            }
        }
    }

    public function testOtherCustomerCannotStartOrQueryPayment(): void
    {
        $gateway = $this->gateway(new MockHandler());
        foreach (['native', 'sync'] as $method) {
            try {
                $gateway->$method($this->user + 1, $this->orderId);
                $this->fail('ownership bypass');
            } catch (\DomainException) {
                $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
            }
        }
    }

    public function testQueryIsAuthenticatedAndCancellingPaidOrderFails(): void
    {
        $mock = new MockHandler([$this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=x']),
            function ($request) {
                $this->assertSame('/v3/pay/transactions/out-trade-no/' . $this->publicId, $request->getUri()->getPath());
                $this->assertSame('mchid=1900000001', $request->getUri()->getQuery());
                return $this->signedResponse($this->transaction());
            }]);
        $gateway = $this->gateway($mock);
        $gateway->native($this->user, $this->orderId);
        try {
            $gateway->closeBeforeCancel($this->user, $this->orderId);
            $this->fail('paid cancellation allowed');
        } catch (\DomainException) {
            $this->assertSame('completed', $this->checkout->byId($this->orderId)['status']);
        }
    }

    public function testProductionNeverAllowsFakeAndMissingWechatFailsClosed(): void
    {
        $this->assertFalse(PaymentMode::demoAllowed(new Config(['APP_ENV' => 'production', 'PAYMENT_DRIVER' => 'fake'])));
        $this->assertTrue(PaymentMode::demoAllowed(new Config(['APP_ENV' => 'local', 'PAYMENT_DRIVER' => 'fake'])));
        $this->assertFalse(PaymentMode::demoAllowed(new Config(['APP_ENV' => 'local', 'PAYMENT_DRIVER' => 'wechat'])));
        $this->expectException(\DomainException::class);
        WechatSettings::fromConfig(new Config(['PAYMENT_DRIVER' => 'wechat']));
    }

    public function testRefundOnlyCompletesAfterAuthenticatedSuccess(): void
    {
        $pay = $this->gateway(new MockHandler([$this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=x'])]));
        $pay->native($this->user, $this->orderId);
        [$body, $headers] = $this->notification($this->transaction());
        $pay->notify($body, $headers);
        $refunds = new Refunds($this->pdo, $this->checkout, $this->audit);
        $id = $refunds->request($this->user, $this->orderId, 1800, '商品未使用', 'wx-refund');
        $refunds->decide($id, 'approved', 1);
        $data = ['out_refund_no' => $this->publicId . '_r' . $id, 'out_trade_no' => $this->publicId,
            'transaction_id' => '420000000123', 'refund_id' => '500000001', 'status' => 'PROCESSING',
            'amount' => ['total' => 1800, 'refund' => 1800, 'currency' => 'CNY']];
        $mock = new MockHandler([function ($request) use ($data) {
            $payload = json_decode((string) $request->getBody(), true);
            $this->assertSame($data['out_refund_no'], $payload['out_refund_no']);
            $this->assertSame(1800, $payload['amount']['refund']);
            return $this->signedResponse($data);
        }, $this->signedResponse(array_replace($data, ['status' => 'SUCCESS']))]);
        $gateway = new WechatRefundGateway($this->pdo, $this->checkout, $refunds, $this->settings,
            new WechatSdkTransport($this->settings, $mock), $this->audit);
        $this->assertSame('PROCESSING', $gateway->submit($id, 1));
        $this->assertSame('approved', $refunds->byId($id)['status']);
        $this->assertSame('refund_requested', $this->checkout->byId($this->orderId)['status']);
        $this->assertSame('SUCCESS', $gateway->sync($id));
        $this->assertSame('refunded', $this->checkout->byId($this->orderId)['status']);
        $this->assertSame('SUCCESS', $gateway->submit($id, 1), 'repeat does not refund again');
        $this->assertSame(0, $mock->count());
    }

    public function testRefundNotifyRejectsAmountMismatchAndCompletesIdempotently(): void
    {
        $pay = $this->gateway(new MockHandler([$this->signedResponse(['code_url' => 'weixin://wxpay/bizpayurl?pr=x'])]));
        $pay->native($this->user, $this->orderId);
        [$body, $headers] = $this->notification($this->transaction());
        $pay->notify($body, $headers);
        $refunds = new Refunds($this->pdo, $this->checkout, $this->audit);
        $id = $refunds->request($this->user, $this->orderId, 1800, '商品未使用', 'wx-refund-notify');
        $refunds->decide($id, 'approved', 1);
        $data = ['mchid' => $this->settings->merchantId, 'out_refund_no' => $this->publicId . '_r' . $id,
            'out_trade_no' => $this->publicId, 'transaction_id' => '420000000123', 'refund_id' => '500000002',
            'refund_status' => 'SUCCESS', 'amount' => ['total' => 1800, 'refund' => 1800]];
        $gateway = new WechatRefundGateway($this->pdo, $this->checkout, $refunds, $this->settings,
            new WechatSdkTransport($this->settings, new MockHandler()), $this->audit);
        $bad = $data; $bad['amount']['refund'] = 1;
        [$body, $headers] = $this->notification($bad, 'REFUND.SUCCESS');
        try { $gateway->notify($body, $headers); $this->fail('refund mismatch accepted'); }
        catch (\DomainException) { $this->assertSame('approved', $refunds->byId($id)['status']); }
        [$body, $headers] = $this->notification($data, 'REFUND.SUCCESS');
        $gateway->notify($body, $headers);
        $gateway->notify($body, $headers);
        $this->assertSame('succeeded', $refunds->byId($id)['status']);
        $this->assertSame('500000002', $refunds->byId($id)['provider_ref']);
    }

    private function gateway(MockHandler $mock): WechatPaymentGateway
    {
        return new WechatPaymentGateway($this->pdo, $this->checkout, $this->settlement, $this->settings,
            new WechatSdkTransport($this->settings, $mock), $this->audit);
    }

    private function transaction(): array
    {
        return ['appid' => $this->settings->appId, 'mchid' => $this->settings->merchantId,
            'out_trade_no' => $this->publicId, 'transaction_id' => '420000000123', 'trade_state' => 'SUCCESS',
            'trade_type' => 'NATIVE', 'amount' => ['total' => 1800, 'currency' => 'CNY']];
    }

    private function headers(string $body): array
    {
        $time = (string) time();
        $nonce = bin2hex(random_bytes(12));
        return ['Wechatpay-Timestamp' => $time, 'Wechatpay-Nonce' => $nonce, 'Wechatpay-Serial' => $this->settings->publicKeyId,
            'Wechatpay-Signature' => Rsa::sign(Formatter::joinedByLineFeed($time, $nonce, $body),
                Rsa::from(self::$keys['platform']['private'], Rsa::KEY_TYPE_PRIVATE))];
    }

    private function signedResponse(array $data): Response
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return new Response(200, $this->headers($body), $body);
    }

    private function notification(array $data, string $eventType = 'TRANSACTION.SUCCESS'): array
    {
        $nonce = '123456789012';
        $aad = 'transaction';
        $cipher = openssl_encrypt(json_encode($data), 'aes-256-gcm', $this->settings->apiV3Key,
            OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        $body = json_encode(['event_type' => $eventType, 'resource_type' => 'encrypt-resource',
            'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'nonce' => $nonce, 'associated_data' => $aad,
                'ciphertext' => base64_encode($cipher . $tag)]]);
        return [$body, $this->headers($body)];
    }
}
