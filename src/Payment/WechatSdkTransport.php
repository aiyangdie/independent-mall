<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

use WeChatPay\Builder;
use WeChatPay\BuilderChainable;
use WeChatPay\Crypto\Rsa;

final class WechatSdkTransport implements WechatTransport
{
    private BuilderChainable $client;

    /** Handler is an isolated-test seam; production always uses the official HTTPS origin. */
    public function __construct(WechatSettings $settings, mixed $handler = null)
    {
        $options = [
            'mchid' => $settings->merchantId, 'serial' => $settings->merchantSerial,
            'privateKey' => Rsa::from($settings->privatePem, Rsa::KEY_TYPE_PRIVATE),
            'certs' => [$settings->publicKeyId => Rsa::from($settings->publicPem, Rsa::KEY_TYPE_PUBLIC)],
            'base_uri' => 'https://api.mch.weixin.qq.com/', 'verify' => true,
            'timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false,
        ];
        if ($handler !== null) {
            // The SDK only recognizes HandlerStack; a raw mock would otherwise be ignored.
            $options['handler'] = $handler instanceof \GuzzleHttp\HandlerStack
                ? $handler : \GuzzleHttp\HandlerStack::create($handler);
        }
        $this->client = Builder::factory($options);
    }

    public function request(string $method, string $path, array $options): array
    {
        $allowed = [
            'POST v3/pay/transactions/native', 'GET v3/pay/transactions/out-trade-no/{out_trade_no}',
            'POST v3/pay/transactions/out-trade-no/{out_trade_no}/close',
            'POST v3/refund/domestic/refunds', 'GET v3/refund/domestic/refunds/{out_refund_no}',
        ];
        if (!in_array($method . ' ' . $path, $allowed, true)) {
            throw new \DomainException('不支持的微信支付操作。');
        }
        try {
            $response = $this->client->chain($path)->{strtolower($method)}($options);
            $body = (string) $response->getBody();
            if (strlen($body) > 1048576) {
                throw new \RuntimeException('Oversized API response');
            }
            $data = $body === '' ? [] : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid API response');
            }
            return $data;
        } catch (\Throwable $e) {
            // Never surface signed request headers, merchant secrets or raw response bodies.
            throw new \DomainException('微信支付请求失败，请稍后重试；管理员请检查商户配置和网络。');
        }
    }
}
