<?php

declare(strict_types=1);

namespace IndependentMall\OfficialSite;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\License\CanonicalJson;
use IndependentMall\License\OpenSslEd25519;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;
use IndependentMall\Update\PackageVerifier;
use PDO;

final class Hub
{
    public const FILES = [
        'sample-notice' => 'sample-notice.txt',
        'community-notes' => 'community-0.1.0-dev.txt',
        'community-0.1.1' => 'community-0.1.1.txt',
    ];

    public function __construct(
        private PDO $pdo,
        private PasswordHasher $hasher,
        private Store $store,
        private string $hmacKey,
        private string $filesDir,
        private string $privatePem,
        private string $publicPem,
    ) {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                amount_minor INTEGER NOT NULL,
                status TEXT NOT NULL,
                adapter TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS account_licenses (
                user_id INTEGER NOT NULL,
                license_id TEXT NOT NULL,
                token_hash TEXT NOT NULL,
                document TEXT NOT NULL,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, license_id)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS security_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                meta TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS download_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                file_id TEXT NOT NULL,
                ok INTEGER NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }

    public function register(string $email, string $password): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        $hash = $this->hasher->hash($password);
        $this->pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (:e, :h, :t)')
            ->execute(['e' => $email, 'h' => $hash, 't' => gmdate('c')]);
        $id = (int) $this->pdo->lastInsertId();
        $this->log($id, 'register', []);
        return $id;
    }

    public function login(string $email, string $password): int
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :e');
        $stmt->execute(['e' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->hasher->verify($password, (string) $row['password_hash'])) {
            $this->log(null, 'login_fail', ['email' => strtolower(trim($email))]);
            throw new \DomainException('Unknown account or password.');
        }
        $id = (int) $row['id'];
        $this->log($id, 'login', []);
        return $id;
    }

    /**
     * Fake commercial SKU only. Does not talk to a card network.
     *
     * @return array{order_id:int,license_id:string,token:string}
     */
    public function fakeBuyNotice(int $userId): array
    {
        $this->pdo->prepare(
            'INSERT INTO orders (user_id, sku, amount_minor, status, adapter, created_at)
             VALUES (:u, :s, :a, :st, :ad, :t)'
        )->execute([
            'u' => $userId,
            's' => 'commercial-sample-notice',
            'a' => 9900,
            'st' => 'fake_completed',
            'ad' => 'fake',
            't' => gmdate('c'),
        ]);
        $orderId = (int) $this->pdo->lastInsertId();
        $licenseId = 'lic_' . bin2hex(random_bytes(6));
        $token = bin2hex(random_bytes(16));
        $this->store->issue($licenseId, $token, ['storefront_notice'], gmdate('c', time() + 86400 * 365));
        $payload = [
            'license_id' => $licenseId,
            'instance_id' => '',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c'),
            'last_refresh_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + 86400 * 365),
            'grace_days' => 14,
            'revoked' => false,
        ];
        $doc = Signer::sign($payload, $this->privatePem);
        $this->pdo->prepare(
            'INSERT INTO account_licenses (user_id, license_id, token_hash, document, created_at)
             VALUES (:u, :l, :h, :d, :t)'
        )->execute([
            'u' => $userId,
            'l' => $licenseId,
            'h' => hash('sha256', $token),
            'd' => json_encode($doc, JSON_UNESCAPED_SLASHES),
            't' => gmdate('c'),
        ]);
        $this->log($userId, 'fake_buy', ['order_id' => $orderId, 'license_id' => $licenseId]);
        return ['order_id' => $orderId, 'license_id' => $licenseId, 'token' => $token];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orders(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE user_id = :u ORDER BY id DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function licenses(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT license_id, document, created_at FROM account_licenses WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(int $userId, string $licenseId, string $token, string $instanceId): array
    {
        $this->assertOwns($userId, $licenseId, $token);
        $payload = $this->store->activate($licenseId, $token, $instanceId);
        $doc = Signer::sign($payload, $this->privatePem);
        $this->pdo->prepare('UPDATE account_licenses SET document = :d WHERE user_id = :u AND license_id = :l')
            ->execute(['d' => json_encode($doc, JSON_UNESCAPED_SLASHES), 'u' => $userId, 'l' => $licenseId]);
        $this->log($userId, 'activate', ['license_id' => $licenseId, 'instance_id' => $instanceId]);
        return $doc;
    }

    public function unbind(int $userId, string $licenseId, string $token): void
    {
        $this->assertOwns($userId, $licenseId, $token);
        $this->store->unbind($licenseId);
        $this->log($userId, 'unbind', ['license_id' => $licenseId]);
    }

    public function openTicket(int $userId, string $title, string $body): void
    {
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('Ticket needs a title and body.');
        }
        $this->pdo->prepare('INSERT INTO tickets (user_id, title, body, created_at) VALUES (:u, :ti, :b, :t)')
            ->execute(['u' => $userId, 'ti' => $title, 'b' => $body, 't' => gmdate('c')]);
        $this->log($userId, 'ticket', ['title' => $title]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tickets(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE user_id = :u ORDER BY id DESC');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function securityLog(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM security_log WHERE user_id = :u ORDER BY id DESC LIMIT 50');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function downloadToken(int $userId, string $fileId): string
    {
        if (!isset(self::FILES[$fileId])) {
            throw new \InvalidArgumentException('Unknown file.');
        }
        $exp = (string) (time() + 600);
        $sig = hash_hmac('sha256', $userId . '|' . $fileId . '|' . $exp, $this->hmacKey);
        return $fileId . '.' . $exp . '.' . $sig;
    }

    public function resolveDownload(int $userId, string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $this->auditDownload($userId, 'invalid', false);
            throw new \DomainException('Bad download token.');
        }
        [$fileId, $exp, $sig] = $parts;
        $expect = hash_hmac('sha256', $userId . '|' . $fileId . '|' . $exp, $this->hmacKey);
        if (!hash_equals($expect, $sig) || time() > (int) $exp || !isset(self::FILES[$fileId])) {
            $this->auditDownload($userId, $fileId, false);
            throw new \DomainException('Download token refused.');
        }
        $path = $this->filesDir . '/' . self::FILES[$fileId];
        $realBase = realpath($this->filesDir);
        $realFile = realpath($path);
        $baseNorm = $realBase === false ? '' : strtolower(str_replace('\\', '/', $realBase));
        $fileNorm = $realFile === false ? '' : strtolower(str_replace('\\', '/', $realFile));
        if ($baseNorm === '' || $fileNorm === '' || !str_starts_with($fileNorm, $baseNorm) || !is_file($realFile)) {
            $this->auditDownload($userId, $fileId, false);
            throw new \DomainException('File missing.');
        }
        $this->auditDownload($userId, $fileId, true);
        return $realFile;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function downloadAudits(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM download_audit WHERE user_id = :u ORDER BY id DESC LIMIT 50');
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updates(): array
    {
        $unsignedPath = $this->filesDir . '/' . self::FILES['community-notes'];
        $signedPath = $this->filesDir . '/' . self::FILES['community-0.1.1'];
        $unsignedHash = is_file($unsignedPath) ? hash_file('sha256', $unsignedPath) : '';
        $signedPayload = [
            'artifact' => 'community-0.1.1',
            'major' => 0,
            'migration' => 'None. Notes file only.',
            'notes' => 'Ed25519 over canonical JSON of the payload without signature fields. Verify SHA-256 before replace.',
            'php' => '>=8.2',
            'rollback' => 'SafeApplier restores the backup directory if the write fails.',
            'sha256' => is_file($signedPath) ? (string) hash_file('sha256', $signedPath) : '',
            'version' => '0.1.1',
        ];
        $signature = base64_encode(OpenSslEd25519::sign(CanonicalJson::encode($signedPayload), $this->privatePem));
        return [
            'channel' => 'community',
            'latest' => [
                'version' => '0.1.0-dev',
                'php' => '>=8.2',
                'artifact' => 'community-notes',
                'sha256' => $unsignedHash,
                'signature' => '',
                'signed' => false,
                'migration' => 'None. Development snapshot.',
                'rollback' => 'Keep the previous directory; do not overwrite until signed packages exist.',
                'notes' => 'Unsigned development metadata. Clients must refuse automatic install and must not execute unsigned code.',
            ],
            'signed_release' => $signedPayload + [
                'signature' => $signature,
                'signed' => true,
            ],
            'update_public_key' => $this->publicPem,
        ];
    }

    /**
     * Verifies update metadata only. Never writes install files.
     *
     * @return array{unsigned:string,signed:string,applied:bool,version:string,sha256:string}
     */
    public function inspectUpdates(?int $userId = null): array
    {
        $doc = $this->updates();
        $unsigned = 'refused_auto_install';
        $signedPath = $this->filesDir . '/' . self::FILES['community-0.1.1'];
        $signed = 'unverified';
        try {
            PackageVerifier::assertSafeToApply(
                $doc['signed_release'],
                $signedPath,
                (string) $doc['update_public_key'],
                '0.1.0',
            );
            $signed = 'verified_not_applied';
        } catch (\Throwable $e) {
            $signed = 'failed:' . $e->getMessage();
        }
        $this->log($userId, 'inspect_updates', ['signed' => $signed, 'applied' => false]);
        return [
            'unsigned' => $unsigned,
            'signed' => $signed,
            'applied' => false,
            'version' => (string) $doc['signed_release']['version'],
            'sha256' => (string) $doc['signed_release']['sha256'],
        ];
    }

    public function publicPem(): string
    {
        return $this->publicPem;
    }

    private function assertOwns(int $userId, string $licenseId, string $token): void
    {
        $stmt = $this->pdo->prepare('SELECT token_hash FROM account_licenses WHERE user_id = :u AND license_id = :l');
        $stmt->execute(['u' => $userId, 'l' => $licenseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            throw new \DomainException('License does not belong to this account.');
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function log(?int $userId, string $action, array $meta): void
    {
        $this->pdo->prepare('INSERT INTO security_log (user_id, action, meta, created_at) VALUES (:u, :a, :m, :t)')
            ->execute([
                'u' => $userId,
                'a' => $action,
                'm' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                't' => gmdate('c'),
            ]);
    }

    private function auditDownload(int $userId, string $fileId, bool $ok): void
    {
        $this->pdo->prepare('INSERT INTO download_audit (user_id, file_id, ok, created_at) VALUES (:u, :f, :o, :t)')
            ->execute(['u' => $userId, 'f' => $fileId, 'o' => $ok ? 1 : 0, 't' => gmdate('c')]);
    }
}
