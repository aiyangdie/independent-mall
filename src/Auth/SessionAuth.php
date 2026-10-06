<?php

declare(strict_types=1);

namespace IndependentMall\Auth;

use IndependentMall\Security\AuditLogger;
use PDO;

final class SessionAuth
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private string $appKey,
    ) {
    }

    public function start(string $savePath, string $name, int $lifetime, bool $secure): void
    {
        if (!is_dir($savePath)) {
            mkdir($savePath, 0770, true);
        }
        session_save_path($savePath);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * @return array{id:int,email:string,username:string}|null
     */
    public function user(): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, email, username, status FROM users WHERE id = :id AND status = 'active' AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'username' => (string) $row['username'],
        ];
    }

    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :u AND p.name = :n'
        );
        $stmt->execute(['u' => $user['id'], 'n' => $permission]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function login(string $identity, string $password, string $ip): bool
    {
        $identity = mb_strtolower(trim($identity));
        if ($identity === '' || $password === '') {
            return false;
        }
        if ($this->limiter->tooMany($identity)) {
            $this->audit->record(null, 'login.rate_limited', 'user', $identity, [], $this->ipHash($ip));
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, password_hash, status FROM users
             WHERE (email = :e OR username = :u) AND deleted_at IS NULL"
        );
        $stmt->execute(['e' => $identity, 'u' => $identity]);
        $row = $stmt->fetch();
        $ok = is_array($row)
            && $row['status'] === 'active'
            && $this->hasher->verify($password, (string) $row['password_hash']);
        $this->limiter->record($identity, $this->ipHash($ip), $ok);
        if (!$ok) {
            $this->audit->record(null, 'login.failed', 'user', $identity, [], $this->ipHash($ip));
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $row['id'];
        $this->audit->record((int) $row['id'], 'login.ok', 'user', (string) $row['id'], [], $this->ipHash($ip));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->appKey !== '' ? $this->appKey : 'dev-ip-hash');
    }
}
