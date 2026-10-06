<?php

declare(strict_types=1);

namespace IndependentMall\License;

interface Clock
{
    public function now(): int;
}
