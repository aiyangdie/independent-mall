<?php

declare(strict_types=1);

namespace IndependentMall\Modules;

final class AbsentLicense implements LicenseGate
{
    public function allows(array $entitlements): bool
    {
        return $entitlements === [];
    }
}
