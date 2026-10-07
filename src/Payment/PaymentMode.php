<?php
declare(strict_types=1);

namespace IndependentMall\Payment;

use IndependentMall\Config\Config;

final class PaymentMode
{
    public static function driver(Config $config): string
    {
        $driver = strtolower(trim($config->get('PAYMENT_DRIVER', '')));
        if ($driver === '') {
            $driver = strtolower(trim($config->get('PAYMENT_ADAPTER', 'fake')));
        }
        return $driver !== '' ? $driver : 'fake';
    }

    public static function demoAllowed(Config $config): bool
    {
        return in_array($config->get('APP_ENV', 'local'), ['local', 'development', 'test', 'testing'], true)
            && self::driver($config) === 'fake';
    }
}
