<?php
declare(strict_types=1);

namespace IndependentMall\Payment;

use IndependentMall\Config\Config;

final class WechatSettings
{
    public function __construct(
        public readonly string $appId,
        public readonly string $merchantId,
        public readonly string $merchantSerial,
        public readonly string $privatePem,
        public readonly string $publicKeyId,
        public readonly string $publicPem,
        public readonly string $apiV3Key,
        public readonly string $notifyUrl,
    ) {
        if (preg_match('/^wx[a-fA-F0-9]{16}$/D', $appId) !== 1
            || preg_match('/^[0-9]{6,32}$/D', $merchantId) !== 1
            || preg_match('/^[a-fA-F0-9]{8,64}$/D', $merchantSerial) !== 1
            || preg_match('/^PUB_KEY_ID_[A-Za-z0-9_]+$/D', $publicKeyId) !== 1
            || strlen($apiV3Key) !== 32) {
            throw new \DomainException('微信支付配置不完整或格式不正确。');
        }
        $private = openssl_pkey_get_private($privatePem);
        $public = openssl_pkey_get_public($publicPem);
        $privateDetails = $private ? openssl_pkey_get_details($private) : false;
        $publicDetails = $public ? openssl_pkey_get_details($public) : false;
        if (!$privateDetails || !$publicDetails || $privateDetails['type'] !== OPENSSL_KEYTYPE_RSA
            || $publicDetails['type'] !== OPENSSL_KEYTYPE_RSA || $privateDetails['bits'] < 2048 || $publicDetails['bits'] < 2048) {
            throw new \DomainException('微信支付需要有效的 RSA 商户私钥及微信支付公钥。');
        }
        $url = parse_url($notifyUrl);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || in_array(strtolower($url['host']), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \DomainException('微信支付回调需要公网 HTTPS 地址。');
        }
    }

    public static function fromConfig(Config $config): self
    {
        if (PaymentMode::driver($config) !== 'wechat') {
            throw new \DomainException('微信支付尚未启用，请联系商家。');
        }
        return new self(
            $config->get('WECHAT_APP_ID'), $config->get('WECHAT_MCH_ID'), $config->get('WECHAT_MERCHANT_SERIAL'),
            self::keyFile($config, 'WECHAT_PRIVATE_KEY_PATH'), $config->get('WECHAT_PUBLIC_KEY_ID'),
            self::keyFile($config, 'WECHAT_PUBLIC_KEY_PATH'), $config->get('WECHAT_API_V3_KEY'),
            rtrim($config->get('APP_URL'), '/') . '/payments/wechat/notify',
        );
    }

    private static function keyFile(Config $config, string $field): string
    {
        $path = $config->get($field);
        if ($path === '' || preg_match('#^[a-z]+://#i', $path) === 1) {
            throw new \DomainException('微信支付密钥文件尚未配置。');
        }
        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = $config->root() . '/' . $path;
        }
        $real = realpath($path);
        $public = realpath($config->root() . '/public');
        if ($real === false || !is_file($real) || !is_readable($real)
            || ($public !== false && str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower(str_replace('\\', '/', $public)) . '/'))) {
            throw new \DomainException('微信支付密钥不可读取或位于不安全目录。');
        }
        $pem = file_get_contents($real);
        if (!is_string($pem)) {
            throw new \DomainException('微信支付密钥不可读取。');
        }
        return $pem;
    }
}
