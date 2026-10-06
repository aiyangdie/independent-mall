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
}
