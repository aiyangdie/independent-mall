<?php

declare(strict_types=1);

namespace IndependentMall\Tests\Support;

use IndependentMall\Modules\Module;
use PDO;

final class FakeNoticeModule implements Module
{
    public function id(): string
    {
        return 'fake-notice';
    }

    public function label(): string
    {
        return 'Fake notice';
    }

    public function entitlements(): array
    {
        return ['storefront_notice'];
    }

    public function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS fake_notice (body TEXT NOT NULL)');
        $n = $pdo->query('SELECT COUNT(*) FROM fake_notice')->fetchColumn();
        if ((int) $n === 0) {
            $pdo->exec("INSERT INTO fake_notice (body) VALUES ('Hello from a test module')");
        }
    }

    public function isMigrated(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM fake_notice LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function enable(PDO $pdo): void
    {
        $this->migrate($pdo);
    }

    public function disable(PDO $pdo): void
    {
    }

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS fake_notice');
    }

    public function storefrontNotice(PDO $pdo): string
    {
        if (!$this->isMigrated($pdo)) {
            return '';
        }
        $row = $pdo->query('SELECT body FROM fake_notice LIMIT 1')->fetch();
        return is_array($row) ? (string) $row['body'] : '';
    }
}
