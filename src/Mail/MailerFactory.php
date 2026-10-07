<?php

declare(strict_types=1);

namespace IndependentMall\Mail;

use IndependentMall\Config\Config;

final class MailerFactory
{
    public static function fromConfig(Config $config): Mailer
    {
        $adapter = strtolower(trim($config->get('MAIL_ADAPTER', 'null')));
        $development = in_array(strtolower($config->get('APP_ENV', 'production')), ['local', 'dev', 'development', 'test', 'testing'], true);
        if ($adapter === 'null' && $development) {
            return new NullMailer();
        }
        if ($adapter !== 'smtp') {
            return new UnavailableMailer();
        }
        try {
            return new SmtpMailer(SmtpSettings::fromConfig($config));
        } catch (\InvalidArgumentException) {
            // Keep the storefront running; the mail operation itself fails closed.
            return new UnavailableMailer();
        }
    }
}
