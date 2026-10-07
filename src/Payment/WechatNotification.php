<?php
declare(strict_types=1);
namespace IndependentMall\Payment;

use WeChatPay\Crypto\AesGcm;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;

final class WechatNotification
{
    public function __construct(private WechatSettings $settings) {}

    /** Authenticate raw bytes BEFORE parsing or decrypting. No payload logging. */
    public function decrypt(string $body, array $headers, ?int $now = null): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = (string) ($headers['wechatpay-timestamp'] ?? '');
        $nonce = (string) ($headers['wechatpay-nonce'] ?? '');
        $signature = (string) ($headers['wechatpay-signature'] ?? '');
        if (strlen($body) > 1048576 || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1
            || abs(($now ?? time()) - (int) $timestamp) > 300 || $nonce === '' || strlen($nonce) > 64
            || strlen($signature) > 2048
            || !hash_equals($this->settings->publicKeyId, (string) ($headers['wechatpay-serial'] ?? ''))) {
            throw new \DomainException('微信支付通知验证失败。');
        }
        try {
            if (!Rsa::verify(Formatter::joinedByLineFeed($timestamp, $nonce, $body), $signature,
                Rsa::from($this->settings->publicPem, Rsa::KEY_TYPE_PUBLIC))) {
                throw new \RuntimeException('Invalid signature');
            }
            $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $resource = $event['resource'] ?? [];
            if (($resource['algorithm'] ?? '') !== 'AEAD_AES_256_GCM'
                || ($event['resource_type'] ?? '') !== 'encrypt-resource') {
                throw new \RuntimeException('Unsupported event');
            }
            $plaintext = AesGcm::decrypt($resource['ciphertext'] ?? '', $this->settings->apiV3Key,
                $resource['nonce'] ?? '', $resource['associated_data'] ?? '');
            $data = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid resource');
            }
            return ['event_type' => $event['event_type'] ?? '', 'resource' => $data];
        } catch (\Throwable $e) {
            throw new \DomainException('微信支付通知验证失败。');
        }
    }
}
