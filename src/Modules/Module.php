<?php

declare(strict_types=1);

namespace IndependentMall\Modules;

use PDO;

interface Module
{
    public function id(): string;

    public function label(): string;

    /**
     * @return list<string>
     */
    public function entitlements(): array;

    public function migrate(PDO $pdo): void;

    public function isMigrated(PDO $pdo): bool;

    public function enable(PDO $pdo): void;

    public function disable(PDO $pdo): void;

    public function uninstall(PDO $pdo): void;

    public function storefrontNotice(PDO $pdo): string;
}
