<?php

declare(strict_types=1);

namespace IndependentMall\Auth;

use DomainException;
use IndependentMall\Mail\Mailer;
use IndependentMall\Mail\Message;
use IndependentMall\Security\AuditLogger;
use PDO;

final class CustomerAccounts
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
        private Mailer $mailer,
        private AuditLogger $audit,
        private SessionAuth $auth,
    ) {
    }

    public function register(string $email, string $username, string $password, string $termsVersion = 'draft-1'): int
    {
        $email = mb_strtolower(trim($email));
        $username = trim($username);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('A valid email is required.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            throw new DomainException('Username must be 3-32 letters, numbers, or underscore.');
        }
        $this->hasher->assertStrong($password);
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
            $role = $this->pdo->query("SELECT id FROM roles WHERE name = 'customer'")->fetch();
            if (!is_array($role)) {
                throw new DomainException('customer role missing');
            }
            $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)')
                ->execute(['u' => $id, 'r' => $role['id']]);
            $this->pdo->prepare(
                'INSERT INTO legal_acceptances (user_id, document_key, version, accepted_at) VALUES (:u, :k, :v, :t)'
            )->execute(['u' => $id, 'k' => 'user-terms', 'v' => $termsVersion, 't' => $now]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new DomainException('That email or username is already registered.');
            }
            throw $e;
        }
        $this->audit->record($id, 'user.register', 'user', (string) $id);
        return $id;
    }

    public function changePassword(int $userId, string $current, string $next): void
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !$this->hasher->verify($current, (string) $row['password_hash'])) {
            throw new DomainException('Current password is incorrect.');
        }
        $this->pdo->prepare('UPDATE users SET password_hash = :p, updated_at = :t WHERE id = :id')
            ->execute(['p' => $this->hasher->hash($next), 't' => gmdate('c'), 'id' => $userId]);
        $this->auth->bumpEpoch($userId);
        $this->audit->record($userId, 'user.password_change', 'user', (string) $userId);
    }

    public function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $stmt = $this->pdo->prepare("SELECT id, email FROM users WHERE email = :e AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute(['e' => $email]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return;
        }
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (:u, :h, :e, :c)'
        )->execute([
            'u' => $row['id'],
            'h' => $hash,
            'e' => gmdate('c', time() + 3600),
            'c' => gmdate('c'),
        ]);
        $this->mailer->send(new Message(
            (string) $row['email'],
            'Password reset',
            'Use this one-time token within one hour: ' . $raw,
        ));
        $this->audit->record((int) $row['id'], 'user.reset_requested', 'user', (string) $row['id']);
    }

    public function completeReset(string $rawToken, string $newPassword): void
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM password_reset_tokens WHERE token_hash = :h'
        );
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['used_at'] !== null || $row['expires_at'] < gmdate('c')) {
            throw new DomainException('Reset token is invalid or expired.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = :t WHERE id = :id')
                ->execute(['t' => gmdate('c'), 'id' => $row['id']]);
            $this->pdo->prepare('UPDATE users SET password_hash = :p, updated_at = :t WHERE id = :id')
                ->execute(['p' => $this->hasher->hash($newPassword), 't' => gmdate('c'), 'id' => $row['user_id']]);
            $this->auth->bumpEpoch((int) $row['user_id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit->record((int) $row['user_id'], 'user.reset_completed', 'user', (string) $row['user_id']);
    }

    public function deactivate(int $userId): void
    {
        $this->pdo->prepare("UPDATE users SET status = 'locked', updated_at = :t WHERE id = :id")
            ->execute(['t' => gmdate('c'), 'id' => $userId]);
        $this->auth->bumpEpoch($userId);
        $this->audit->record($userId, 'user.locked', 'user', (string) $userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function directory(): array
    {
        return $this->pdo->query(
            "SELECT u.id, u.email, u.username, u.status, u.created_at,
                    GROUP_CONCAT(r.name) AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id
             ORDER BY u.id"
        )->fetchAll() ?: [];
    }

    public function setStatus(int $userId, string $status, int $actorId): void
    {
        if (!in_array($status, ['active', 'locked'], true)) {
            throw new DomainException('Unsupported account status.');
        }
        if ($userId === $actorId) {
            throw new DomainException('You cannot change your own account status here.');
        }
        if ($this->hasAdminAccess($userId)) {
            throw new DomainException('Administrator accounts cannot be locked from this screen.');
        }
        $this->pdo->prepare('UPDATE users SET status = :s, updated_at = :t WHERE id = :id AND deleted_at IS NULL')
            ->execute(['s' => $status, 't' => gmdate('c'), 'id' => $userId]);
        $this->auth->bumpEpoch($userId);
        $this->audit->record($actorId, 'user.status', 'user', (string) $userId, ['status' => $status]);
    }

    private function hasAdminAccess(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :u AND p.name = 'admin.access' LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        return (bool) $stmt->fetch();
    }
}
