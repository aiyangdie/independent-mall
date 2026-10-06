<?php

declare(strict_types=1);

namespace IndependentMall\Modules;

final class AllowListLicense implements LicenseGate
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(private array $allowed)
    {
    }

    public function allows(array $entitlements): bool
    {
        foreach ($entitlements as $need) {
            if (!in_array($need, $this->allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
