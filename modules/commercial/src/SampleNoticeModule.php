<?php

declare(strict_types=1);

namespace IndependentMall\Commercial;

use IndependentMall\Modules\Module;
use PDO;

/**
 * Harmless paid add-on: an optional storefront notice. No payments, inventory, or license private keys.
 */
final class SampleNoticeModule implements Module
{
    public function id(): string
    {
        return 'sample-notice';
    }

    public function label(): string
    {
        return '店面公告（示例商业模块）';
    }

    public function entitlements(): array
    {
        return ['storefront_notice'];
    }

    public function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS commercial_sample_notices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $n = $pdo->query('SELECT COUNT(*) FROM commercial_sample_notices')->fetchColumn();
        if ((int) $n === 0) {
            $pdo->prepare('INSERT INTO commercial_sample_notices (body, updated_at) VALUES (:b, :t)')
                ->execute(['b' => '示例商业公告：关闭本模块后，社区版结账仍可正常使用。', 't' => gmdate('c')]);
        }
    }

    public function isMigrated(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM commercial_sample_notices LIMIT 1');
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
        $pdo->exec('DROP TABLE IF EXISTS commercial_sample_notices');
    }

    public function storefrontNotice(PDO $pdo): string
    {
        if (!$this->isMigrated($pdo)) {
            return '';
        }
        $row = $pdo->query('SELECT body FROM commercial_sample_notices ORDER BY id DESC LIMIT 1')->fetch();
        return is_array($row) ? (string) $row['body'] : '';
    }
}
