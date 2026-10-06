<?php

declare(strict_types=1);

namespace IndependentMall\Modules;

interface LicenseGate
{
    /**
     * @param list<string> $entitlements
     */
    public function allows(array $entitlements): bool;
}
