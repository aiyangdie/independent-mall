<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

final class Money
{
    public static function format(int $minor, string $currency = 'CNY'): string
    {
        $major = intdiv($minor, 100);
        $cents = abs($minor % 100);
        $currency = strtoupper(trim($currency));
        $prefix = $currency === 'CNY' ? '¥' : $currency . ' ';
        // Keep the calculation in integers, including negative sub-yuan amounts.
        return ($minor < 0 ? '-' : '') . $prefix . sprintf('%d.%02d', abs($major), $cents);
    }
}
