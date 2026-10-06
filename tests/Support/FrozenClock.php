<?php

declare(strict_types=1);

namespace IndependentMall\Tests\Support;

use IndependentMall\License\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}
