<?php

declare(strict_types=1);

namespace IndependentMall\Auth;

use PDO;

final class AdminProvisioner
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
    ) {
    }

    public function create(string $email, string $username, string $password): int
    {
        $email = mb_strtolower(trim($email));
        $username = trim($username);
        if ($email === '' || $username === '' || strlen($password) < 10) {
            throw new \InvalidArgumentException('Admin email, username and a password of at least 10 characters are required.');
        }
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, username, password_hash, status, created_at, updated_at)
                 VALUES (:e, :u, :p, :s, :c, :x)'
            );
            $stmt->execute([
                'e' => $email,
                'u' => $username,
                'p' => $this->hasher->hash($password),
                's' => 'active',
                'c' => $now,
                'x' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $role = $this->pdo->query("SELECT id FROM roles WHERE name = 'admin'")->fetch();
            if (!is_array($role)) {
                throw new \RuntimeException('admin role missing');
            }
            $link = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)');
            $link->execute(['u' => $id, 'r' => $role['id']]);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
