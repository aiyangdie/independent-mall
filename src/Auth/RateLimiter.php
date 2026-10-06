<?php

declare(strict_types=1);

namespace IndependentMall\Auth;

use PDO;

final class RateLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function tooMany(string $identity, int $windowSeconds = 900, int $maxFailures = 8): bool
    {
        $since = gmdate('c', time() - $windowSeconds);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identity = :i AND success = 0 AND created_at >= :t'
        );
        $stmt->execute(['i' => $identity, 't' => $since]);
        return (int) $stmt->fetchColumn() >= $maxFailures;
    }

    public function record(string $identity, string $ipHash, bool $success): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (identity, ip_hash, success, created_at) VALUES (:i, :p, :s, :t)'
        );
        $stmt->execute([
            'i' => $identity,
            'p' => $ipHash,
            's' => $success ? 1 : 0,
            't' => gmdate('c'),
        ]);
    }
}
