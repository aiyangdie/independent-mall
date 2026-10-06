<?php

declare(strict_types=1);

namespace IndependentMall\Security;

use PDO;

final class AuditLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, scalar|null> $meta
     */
    public function record(?int $actorId, string $action, string $entity = '', string $entityId = '', array $meta = [], string $ipHash = ''): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity, entity_id, meta, ip_hash, created_at)
             VALUES (:a, :c, :e, :i, :m, :p, :t)'
        );
        $stmt->execute([
            'a' => $actorId,
            'c' => $action,
            'e' => $entity,
            'i' => $entityId,
            'm' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'p' => $ipHash,
            't' => gmdate('c'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100, string $actionPrefix = ''): array
    {
        $limit = max(1, min(500, $limit));
        $prefix = trim($actionPrefix);
        if ($prefix !== '' && preg_match('/^[a-z0-9._-]{1,64}$/i', $prefix) !== 1) {
            throw new \InvalidArgumentException('Audit action filter is not acceptable.');
        }
        if ($prefix === '') {
            $stmt = $this->pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ' . $limit);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM audit_logs WHERE action LIKE :p ESCAPE \'\\\' ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute(['p' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
