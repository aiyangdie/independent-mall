<?php

declare(strict_types=1);

namespace IndependentMall\Config;

use DomainException;
use IndependentMall\Security\AuditLogger;
use PDO;

final class ShopSettings
{
    /** @var list<string> */
    public const KEYS = ['shop_name', 'support_email'];

    public function __construct(private PDO $pdo, private ?AuditLogger $audit = null)
    {
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM system_settings WHERE key = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return is_array($row) ? (string) $row['value'] : $default;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = ['shop_name' => 'Independent Mall', 'support_email' => ''];
        $rows = $this->pdo->query('SELECT key, value FROM system_settings')->fetchAll() ?: [];
        foreach ($rows as $row) {
            if (in_array($row['key'], self::KEYS, true)) {
                $out[$row['key']] = (string) $row['value'];
            }
        }
        return $out;
    }

    public function put(string $key, string $value, ?int $actorId): void
    {
        if (preg_match('/^module\.[a-z0-9-]+\.enabled$/', $key) === 1) {
            if ($value !== '0' && $value !== '1') {
                throw new DomainException('Module flags must be 0 or 1.');
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO system_settings (key, value, updated_at) VALUES (:k, :v, :t)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            );
            $stmt->execute(['k' => $key, 'v' => $value, 't' => gmdate('c')]);
            $this->audit?->record($actorId, 'settings.update', 'setting', $key);
            return;
        }
        if (!in_array($key, self::KEYS, true)) {
            throw new DomainException('Unknown setting.');
        }
        $value = trim($value);
        if ($key === 'shop_name') {
            if ($value === '' || mb_strlen($value) > 80) {
                throw new DomainException('Shop name must be 1–80 characters.');
            }
        }
        if ($key === 'support_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Support email is invalid.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_settings (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute(['k' => $key, 'v' => $value, 't' => gmdate('c')]);
        $this->audit?->record($actorId, 'settings.update', 'setting', $key);
    }
}
