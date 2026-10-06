<?php

declare(strict_types=1);

namespace IndependentMall\License;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
