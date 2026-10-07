<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

use IndependentMall\Config\Config;

final class ResetLink
{
    private string $baseUrl;

    public function __construct(string $baseUrl, bool $allowLocalHttp = false)
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $localHttp = $allowLocalHttp && $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        if (!is_array($parts) || $host === '' || ($scheme !== 'https' && !$localHttp)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\r\n\x00\s]/', $baseUrl) === 1
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('APP_URL 必须是可信的 HTTPS 网站地址（本地测试可使用回环 HTTP）。');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromConfig(Config $config): self
    {
        $development = in_array(strtolower($config->get('APP_ENV', 'production')), ['local', 'dev', 'development', 'test', 'testing'], true);
        return new self($config->get('APP_URL'), $development);
    }

    public function forToken(string $token): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('重置链接无效。');
        }
        return $this->baseUrl . '/reset?token=' . rawurlencode($token);
    }
}
