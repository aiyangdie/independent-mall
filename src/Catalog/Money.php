<?php

declare(strict_types=1);

namespace IndependentMall\Catalog;

final class Money
{
    public static function format(int $minor, string $currency = 'CNY'): string
    {
        $major = intdiv($minor, 100);
        $cents = abs($minor % 100);
        return sprintf('%s %d.%02d', $currency, $major, $cents);
    }
}
