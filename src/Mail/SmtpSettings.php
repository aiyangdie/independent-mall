<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

use IndependentMall\Config\Config;
use InvalidArgumentException;

final readonly class SmtpSettings
{
    private function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public bool $authenticate,
        public string $username,
        public string $password,
        public string $fromAddress,
        public string $fromName,
        public int $timeout,
        public ?string $caFile,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $development = in_array(strtolower($config->get('APP_ENV', 'production')), ['local', 'dev', 'development', 'test', 'testing'], true);
        $host = trim($config->get('SMTP_HOST'));
        $encryption = strtolower(trim($config->get('SMTP_ENCRYPTION', 'tls')));
        $port = $config->get('SMTP_PORT', $encryption === 'ssl' ? '465' : '587');
        $timeout = $config->get('SMTP_TIMEOUT', '10');
        $authValue = strtolower(trim($config->get('SMTP_AUTH', '1')));
        $username = $config->get('SMTP_USERNAME');
        $password = $config->get('SMTP_PASSWORD');
        $from = trim($config->get('MAIL_FROM_ADDRESS'));
        $name = $config->get('MAIL_FROM_NAME', '独立商城');
        $ca = trim($config->get('SMTP_CA_FILE'));
        $isHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $host) === 1;
        if (!$isHost || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
            || !ctype_digit($timeout) || (int) $timeout < 1 || (int) $timeout > 30
            || !in_array($encryption, ['tls', 'ssl', 'none'], true)
            || !in_array($authValue, ['0', '1'], true)
            || filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n\x00]/', $from . $name . $username) === 1
            || ($ca !== '' && (!is_file($ca) || !is_readable($ca)))) {
            throw new InvalidArgumentException('邮件配置不完整或无效。');
        }
        $authenticate = $authValue === '1';
        if (($authenticate && ($username === '' || $password === '')) || (!$development && !$authenticate)) {
            throw new InvalidArgumentException('邮件认证配置不完整或无效。');
        }
        // Unencrypted SMTP is only for an explicit local test sink, never a remote host.
        if ($encryption === 'none' && (!$development || !in_array($host, ['127.0.0.1', '::1', 'localhost'], true))) {
            throw new InvalidArgumentException('邮件服务器必须使用 TLS 加密。');
        }
        return new self($host, (int) $port, $encryption, $authenticate, $username, $password, $from, $name, (int) $timeout, $ca === '' ? null : $ca);
    }
}
