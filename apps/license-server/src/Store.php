<?php

declare(strict_types=1);

namespace IndependentMall\LicenseServer;

use PDO;

final class Store
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS licenses (
                id TEXT PRIMARY KEY,
                token_hash TEXT NOT NULL,
                entitlements TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                grace_days INTEGER NOT NULL DEFAULT 14,
                instance_id TEXT,
                revoked INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS license_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id TEXT NOT NULL,
                action TEXT NOT NULL,
                meta TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL
            )"
        );
    }

    /**
     * @param list<string> $entitlements
     */
    public function issue(string $id, string $token, array $entitlements, string $expiresAt, int $graceDays = 14): void
    {
        $now = gmdate('c');
        $this->pdo->prepare(
            'INSERT INTO licenses (id, token_hash, entitlements, expires_at, grace_days, created_at)
             VALUES (:i, :h, :e, :x, :g, :c)'
        )->execute([
            'i' => $id,
            'h' => hash('sha256', $token),
            'e' => json_encode($entitlements, JSON_THROW_ON_ERROR),
            'x' => $expiresAt,
            'g' => $graceDays,
            'c' => $now,
        ]);
        $this->audit($id, 'issue', ['entitlements' => $entitlements]);
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $id, string $token, string $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            throw new \DomainException('Unknown license.');
        }
        if ((int) $row['revoked'] === 1) {
            throw new \DomainException('License revoked.');
        }
        $instanceId = $this->requireInstanceId($instanceId);
        $bound = (string) ($row['instance_id'] ?? '');
        if ($bound !== '' && $bound !== $instanceId) {
            throw new \DomainException('License is bound to another instance.');
        }
        $this->pdo->prepare('UPDATE licenses SET instance_id = :n WHERE id = :i')
            ->execute(['n' => $instanceId, 'i' => $id]);
        $this->audit($id, 'activate', ['instance_id' => $instanceId]);
        $ents = json_decode((string) $row['entitlements'], true);
        return [
            'license_id' => $id,
            'instance_id' => $instanceId,
            'entitlements' => is_array($ents) ? $ents : [],
            'issued_at' => gmdate('c'),
            'last_refresh_at' => gmdate('c'),
            'expires_at' => $row['expires_at'],
            'grace_days' => (int) $row['grace_days'],
            'revoked' => false,
        ];
    }

    public function revoke(string $id): void
    {
        $this->pdo->prepare('UPDATE licenses SET revoked = 1 WHERE id = :i')->execute(['i' => $id]);
        $this->audit($id, 'revoke', []);
    }

    public function unbind(string $id): void
    {
        $this->pdo->prepare('UPDATE licenses SET instance_id = NULL WHERE id = :i')->execute(['i' => $id]);
        $this->audit($id, 'unbind', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $id, string $token, string $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            throw new \DomainException('Unknown license.');
        }
        if ((int) $row['revoked'] === 1) {
            throw new \DomainException('License revoked.');
        }
        $instanceId = $this->requireInstanceId($instanceId);
        $bound = (string) ($row['instance_id'] ?? '');
        if ($bound === '' || $bound !== $instanceId) {
            throw new \DomainException('License is not bound to this instance.');
        }
        $this->audit($id, 'refresh', ['instance_id' => $instanceId]);
        $ents = json_decode((string) $row['entitlements'], true);
        return [
            'license_id' => $id,
            'instance_id' => $instanceId,
            'entitlements' => is_array($ents) ? $ents : [],
            'issued_at' => (string) $row['created_at'],
            'last_refresh_at' => gmdate('c'),
            'expires_at' => $row['expires_at'],
            'grace_days' => (int) $row['grace_days'],
            'revoked' => false,
        ];
    }

    private function requireInstanceId(string $instanceId): string
    {
        $instanceId = trim($instanceId);
        if (preg_match('/^[a-zA-Z0-9._:-]{3,128}$/', $instanceId) !== 1) {
            throw new \DomainException('Instance id is not acceptable.');
        }
        return $instanceId;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function audit(string $id, string $action, array $meta): void
    {
        $this->pdo->prepare(
            'INSERT INTO license_audit (license_id, action, meta, created_at) VALUES (:i, :a, :m, :t)'
        )->execute([
            'i' => $id,
            'a' => $action,
            'm' => json_encode($meta, JSON_UNESCAPED_SLASHES),
            't' => gmdate('c'),
        ]);
    }
}
