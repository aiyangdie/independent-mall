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
                activation_nonce_hash TEXT NOT NULL DEFAULT \'\',
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
        $this->ensureNonceColumn();
    }

    /**
     * @param list<string> $entitlements
     * @return string One-time activation nonce (not stored in plaintext)
     */
    public function issue(string $id, string $token, array $entitlements, string $expiresAt, int $graceDays = 14): string
    {
        $now = gmdate('c');
        $nonce = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO licenses (id, token_hash, entitlements, expires_at, grace_days, activation_nonce_hash, created_at)
             VALUES (:i, :h, :e, :x, :g, :n, :c)'
        )->execute([
            'i' => $id,
            'h' => hash('sha256', $token),
            'e' => json_encode($entitlements, JSON_THROW_ON_ERROR),
            'x' => $expiresAt,
            'g' => $graceDays,
            'n' => hash('sha256', $nonce),
            'c' => $now,
        ]);
        $this->audit($id, 'issue', ['entitlements' => $entitlements]);
        return $nonce;
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $id, string $token, string $instanceId, string $nonce): array
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
        if ($bound !== '') {
            throw new \DomainException('Already bound. Use refresh.');
        }
        $expect = (string) ($row['activation_nonce_hash'] ?? '');
        if ($expect === '' || !hash_equals($expect, hash('sha256', trim($nonce)))) {
            throw new \DomainException('Activation nonce is not valid.');
        }
        $this->pdo->prepare('UPDATE licenses SET instance_id = :n, activation_nonce_hash = :z WHERE id = :i')
            ->execute(['n' => $instanceId, 'z' => '', 'i' => $id]);
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

    /**
     * Marks the license revoked and returns a payload the issuer can sign for shops.
     *
     * @return array<string, mixed>
     */
    public function revokeIfAuthorized(string $id, string $token): array
    {
        $row = $this->requireRow($id, $token);
        if ((int) $row['revoked'] !== 1) {
            $this->revoke($id);
            $row['revoked'] = 1;
        }
        return $this->payloadFromRow($row, true);
    }

    public function unbind(string $id): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->pdo->prepare('UPDATE licenses SET instance_id = NULL, activation_nonce_hash = :n WHERE id = :i')
            ->execute(['n' => hash('sha256', $nonce), 'i' => $id]);
        $this->audit($id, 'unbind', []);
        return $nonce;
    }

    /**
     * Deletes the license row and its audit rows after token check. Irreversible.
     */
    public function purge(string $id, string $token): void
    {
        $this->requireRow($id, $token);
        $this->pdo->prepare('DELETE FROM license_audit WHERE license_id = :i')->execute(['i' => $id]);
        $this->pdo->prepare('DELETE FROM licenses WHERE id = :i')->execute(['i' => $id]);
    }

    public function exists(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Read-only operator summary. Never returns token or nonce material.
     *
     * @return array<string, mixed>
     */
    public function status(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Unknown license.');
        }
        $ents = json_decode((string) $row['entitlements'], true);
        $instance = (string) ($row['instance_id'] ?? '');
        $auditStmt = $this->pdo->prepare(
            'SELECT action, meta, created_at FROM license_audit WHERE license_id = :i ORDER BY id DESC LIMIT 5'
        );
        $auditStmt->execute(['i' => $id]);
        $recent = [];
        foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $entry) {
            $meta = json_decode((string) ($entry['meta'] ?? '{}'), true);
            if (!is_array($meta)) {
                $meta = [];
            }
            unset($meta['token'], $meta['nonce'], $meta['token_hash'], $meta['activation_nonce']);
            $recent[] = [
                'action' => (string) $entry['action'],
                'created_at' => (string) $entry['created_at'],
                'meta' => $meta,
            ];
        }
        return [
            'license_id' => (string) $row['id'],
            'bound' => $instance !== '',
            'instance_id' => $instance,
            'revoked' => (int) $row['revoked'] === 1,
            'expires_at' => (string) $row['expires_at'],
            'grace_days' => (int) $row['grace_days'],
            'entitlements' => is_array($ents) ? array_values(array_map('strval', $ents)) : [],
            'created_at' => (string) $row['created_at'],
            'has_activation_nonce' => (string) ($row['activation_nonce_hash'] ?? '') !== '',
            'recent_audit' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $id, string $token, string $instanceId): array
    {
        $row = $this->requireRow($id, $token);
        $instanceId = $this->requireInstanceId($instanceId);
        $bound = (string) ($row['instance_id'] ?? '');
        if ($bound === '' || $bound !== $instanceId) {
            throw new \DomainException('License is not bound to this instance.');
        }
        if ((int) $row['revoked'] === 1) {
            $this->audit($id, 'refresh', ['instance_id' => $instanceId, 'revoked' => true]);
            return $this->payloadFromRow($row, true);
        }
        $this->audit($id, 'refresh', ['instance_id' => $instanceId]);
        return $this->payloadFromRow($row, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRow(string $id, string $token): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = :i');
        $stmt->execute(['i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            throw new \DomainException('Unknown license.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payloadFromRow(array $row, bool $revoked): array
    {
        $ents = json_decode((string) $row['entitlements'], true);
        return [
            'license_id' => (string) $row['id'],
            'instance_id' => (string) ($row['instance_id'] ?? ''),
            'entitlements' => is_array($ents) ? $ents : [],
            'issued_at' => (string) $row['created_at'],
            'last_refresh_at' => gmdate('c'),
            'expires_at' => $row['expires_at'],
            'grace_days' => (int) $row['grace_days'],
            'revoked' => $revoked,
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

    private function ensureNonceColumn(): void
    {
        $names = [];
        foreach ($this->pdo->query('PRAGMA table_info(licenses)') as $col) {
            $names[] = (string) $col['name'];
        }
        if (!in_array('activation_nonce_hash', $names, true)) {
            $this->pdo->exec("ALTER TABLE licenses ADD COLUMN activation_nonce_hash TEXT NOT NULL DEFAULT ''");
        }
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
