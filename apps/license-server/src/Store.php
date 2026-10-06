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
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS products (
                code TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                entitlements TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS product_versions (
                product_code TEXT NOT NULL,
                version TEXT NOT NULL,
                notes TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL,
                PRIMARY KEY (product_code, version)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS customers (
                id TEXT PRIMARY KEY,
                email TEXT NOT NULL UNIQUE,
                note TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS commercial_orders (
                id TEXT PRIMARY KEY,
                customer_id TEXT NOT NULL,
                product_code TEXT NOT NULL,
                product_version TEXT NOT NULL,
                license_id TEXT NOT NULL DEFAULT \'\',
                amount_minor INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT \'XXX\',
                status TEXT NOT NULL,
                adapter TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $this->ensureNonceColumn();
        $this->ensureLicenseProductColumns();
        $this->ensureLicenseCustomerColumn();
    }

    /**
     * @param list<string> $entitlements
     */
    public function registerProduct(string $code, string $name, array $entitlements): void
    {
        $code = $this->requireProductCode($code);
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException('Product name is not acceptable.');
        }
        $ents = $this->normalizeEntitlements($entitlements);
        if ($ents === []) {
            throw new \InvalidArgumentException('Product needs at least one entitlement.');
        }
        $this->pdo->prepare(
            'INSERT INTO products (code, name, entitlements, created_at) VALUES (:c, :n, :e, :t)'
        )->execute([
            'c' => $code,
            'n' => $name,
            'e' => json_encode($ents, JSON_THROW_ON_ERROR),
            't' => gmdate('c'),
        ]);
    }

    public function registerVersion(string $productCode, string $version, string $notes = ''): void
    {
        $productCode = $this->requireProductCode($productCode);
        $version = $this->requireVersion($version);
        $notes = trim($notes);
        if (strlen($notes) > 500) {
            throw new \InvalidArgumentException('Version notes are too long.');
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE code = :c');
        $stmt->execute(['c' => $productCode]);
        if (!$stmt->fetchColumn()) {
            throw new \DomainException('Unknown product.');
        }
        $this->pdo->prepare(
            'INSERT INTO product_versions (product_code, version, notes, created_at) VALUES (:p, :v, :n, :t)'
        )->execute([
            'p' => $productCode,
            'v' => $version,
            'n' => $notes,
            't' => gmdate('c'),
        ]);
    }

    /**
     * Read-only catalog for operators. No tokens or private keys.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        $products = $this->pdo->query('SELECT code, name, entitlements, created_at FROM products ORDER BY code')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($products as $product) {
            $ents = json_decode((string) $product['entitlements'], true);
            $vstmt = $this->pdo->prepare(
                'SELECT version, notes, created_at FROM product_versions WHERE product_code = :c ORDER BY version'
            );
            $vstmt->execute(['c' => $product['code']]);
            $versions = [];
            foreach ($vstmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ver) {
                $versions[] = [
                    'version' => (string) $ver['version'],
                    'notes' => (string) $ver['notes'],
                    'created_at' => (string) $ver['created_at'],
                ];
            }
            $out[] = [
                'code' => (string) $product['code'],
                'name' => (string) $product['name'],
                'entitlements' => is_array($ents) ? array_values(array_map('strval', $ents)) : [],
                'versions' => $versions,
                'created_at' => (string) $product['created_at'],
            ];
        }
        return $out;
    }

    public function registerCustomer(string $id, string $email, string $note = ''): void
    {
        $id = $this->requireCustomerId($id);
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Customer email is not acceptable.');
        }
        $note = trim($note);
        if (strlen($note) > 500) {
            throw new \InvalidArgumentException('Customer note is too long.');
        }
        $this->pdo->prepare(
            'INSERT INTO customers (id, email, note, created_at) VALUES (:i, :e, :n, :t)'
        )->execute([
            'i' => $id,
            'e' => $email,
            'n' => $note,
            't' => gmdate('c'),
        ]);
    }

    /**
     * Returns an existing customer id for the email, or registers `$id` when new.
     */
    public function ensureCustomer(string $id, string $email, string $note = ''): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Customer email is not acceptable.');
        }
        $byEmail = $this->pdo->prepare('SELECT id FROM customers WHERE email = :e');
        $byEmail->execute(['e' => $email]);
        $existing = $byEmail->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $id = $this->requireCustomerId($id);
        $byId = $this->pdo->prepare('SELECT id FROM customers WHERE id = :i');
        $byId->execute(['i' => $id]);
        if ($byId->fetchColumn()) {
            return $id;
        }
        $this->registerCustomer($id, $email, $note);
        return $id;
    }

    /**
     * Commercial B2B contacts for this issuer only — not Community shop shoppers.
     *
     * @return list<array<string, mixed>>
     */
    public function customers(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, email, note, created_at FROM customers ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM licenses WHERE customer_id = :c');
            $countStmt->execute(['c' => $row['id']]);
            $out[] = [
                'id' => (string) $row['id'],
                'email' => (string) $row['email'],
                'note' => (string) $row['note'],
                'license_count' => (int) $countStmt->fetchColumn(),
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Fake commercial fulfillment: order row + catalog license for a B2B customer.
     * Does not talk to a card network. Amount is display bookkeeping only.
     *
     * @return string Activation nonce (shown once)
     */
    public function fulfillFakeOrder(
        string $orderId,
        string $customerId,
        string $productCode,
        string $productVersion,
        string $licenseId,
        string $token,
        string $expiresAt,
        int $amountMinor,
        int $graceDays = 14,
    ): string {
        $orderId = $this->requireOrderId($orderId);
        $customerId = $this->requireCustomerId($customerId);
        $productCode = $this->requireProductCode($productCode);
        $productVersion = $this->requireVersion($productVersion);
        if ($amountMinor < 0 || $amountMinor > 1_000_000_000) {
            throw new \InvalidArgumentException('Order amount is not acceptable.');
        }
        $cstmt = $this->pdo->prepare('SELECT 1 FROM customers WHERE id = :i');
        $cstmt->execute(['i' => $customerId]);
        if (!$cstmt->fetchColumn()) {
            throw new \DomainException('Unknown customer.');
        }
        $dup = $this->pdo->prepare('SELECT 1 FROM commercial_orders WHERE id = :i');
        $dup->execute(['i' => $orderId]);
        if ($dup->fetchColumn()) {
            throw new \DomainException('Order already exists.');
        }
        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $this->pdo->prepare(
                'INSERT INTO commercial_orders
                 (id, customer_id, product_code, product_version, license_id, amount_minor, currency, status, adapter, created_at)
                 VALUES (:i, :c, :pc, :pv, :l, :a, :cur, :st, :ad, :t)'
            )->execute([
                'i' => $orderId,
                'c' => $customerId,
                'pc' => $productCode,
                'pv' => $productVersion,
                'l' => '',
                'a' => $amountMinor,
                'cur' => 'XXX',
                'st' => 'fake_completed',
                'ad' => 'fake',
                't' => $now,
            ]);
            $nonce = $this->issueForProduct(
                $licenseId,
                $token,
                $productCode,
                $productVersion,
                $expiresAt,
                $graceDays,
                $customerId,
            );
            $this->pdo->prepare('UPDATE commercial_orders SET license_id = :l WHERE id = :i')
                ->execute(['l' => $licenseId, 'i' => $orderId]);
            $this->audit($licenseId, 'order_fulfill', [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'product_code' => $productCode,
                'product_version' => $productVersion,
                'amount_minor' => $amountMinor,
                'adapter' => 'fake',
            ]);
            $this->pdo->commit();
            return $nonce;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commercialOrders(?string $customerId = null): array
    {
        if ($customerId !== null && $customerId !== '') {
            $customerId = $this->requireCustomerId($customerId);
            $stmt = $this->pdo->prepare(
                'SELECT id, customer_id, product_code, product_version, license_id, amount_minor, currency, status, adapter, created_at
                 FROM commercial_orders WHERE customer_id = :c ORDER BY created_at DESC'
            );
            $stmt->execute(['c' => $customerId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = $this->pdo->query(
                'SELECT id, customer_id, product_code, product_version, license_id, amount_minor, currency, status, adapter, created_at
                 FROM commercial_orders ORDER BY created_at DESC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) $row['id'],
                'customer_id' => (string) $row['customer_id'],
                'product_code' => (string) $row['product_code'],
                'product_version' => (string) $row['product_version'],
                'license_id' => (string) $row['license_id'],
                'amount_minor' => (int) $row['amount_minor'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'],
                'adapter' => (string) $row['adapter'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Issue a license from a registered product + version. Entitlements come from the product row.
     *
     * @return string One-time activation nonce (not stored in plaintext)
     */
    public function issueForProduct(
        string $id,
        string $token,
        string $productCode,
        string $productVersion,
        string $expiresAt,
        int $graceDays = 14,
        string $customerId = '',
    ): string {
        $productCode = $this->requireProductCode($productCode);
        $productVersion = $this->requireVersion($productVersion);
        $stmt = $this->pdo->prepare('SELECT entitlements FROM products WHERE code = :c');
        $stmt->execute(['c' => $productCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException('Unknown product.');
        }
        $vstmt = $this->pdo->prepare(
            'SELECT 1 FROM product_versions WHERE product_code = :p AND version = :v'
        );
        $vstmt->execute(['p' => $productCode, 'v' => $productVersion]);
        if (!$vstmt->fetchColumn()) {
            throw new \DomainException('Unknown product version.');
        }
        $ents = json_decode((string) $row['entitlements'], true);
        if (!is_array($ents)) {
            throw new \DomainException('Product entitlements are corrupt.');
        }
        return $this->issue(
            $id,
            $token,
            array_values(array_map('strval', $ents)),
            $expiresAt,
            $graceDays,
            $productCode,
            $productVersion,
            $customerId,
        );
    }

    /**
     * @param list<string> $entitlements
     * @return string One-time activation nonce (not stored in plaintext)
     */
    public function issue(
        string $id,
        string $token,
        array $entitlements,
        string $expiresAt,
        int $graceDays = 14,
        string $productCode = '',
        string $productVersion = '',
        string $customerId = '',
    ): string {
        $ents = $this->normalizeEntitlements($entitlements);
        if ($ents === []) {
            throw new \InvalidArgumentException('License needs at least one entitlement.');
        }
        if ($productCode !== '') {
            $productCode = $this->requireProductCode($productCode);
            $productVersion = $this->requireVersion($productVersion);
        } else {
            $productVersion = '';
        }
        if ($customerId !== '') {
            $customerId = $this->requireCustomerId($customerId);
            $cstmt = $this->pdo->prepare('SELECT 1 FROM customers WHERE id = :i');
            $cstmt->execute(['i' => $customerId]);
            if (!$cstmt->fetchColumn()) {
                throw new \DomainException('Unknown customer.');
            }
        }
        $now = gmdate('c');
        $nonce = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO licenses (id, token_hash, entitlements, expires_at, grace_days, activation_nonce_hash, product_code, product_version, customer_id, created_at)
             VALUES (:i, :h, :e, :x, :g, :n, :pc, :pv, :cu, :c)'
        )->execute([
            'i' => $id,
            'h' => hash('sha256', $token),
            'e' => json_encode($ents, JSON_THROW_ON_ERROR),
            'x' => $expiresAt,
            'g' => $graceDays,
            'n' => hash('sha256', $nonce),
            'pc' => $productCode,
            'pv' => $productVersion,
            'cu' => $customerId,
            'c' => $now,
        ]);
        $this->audit($id, 'issue', [
            'entitlements' => $ents,
            'product_code' => $productCode,
            'product_version' => $productVersion,
            'customer_id' => $customerId,
        ]);
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
        $row['instance_id'] = $instanceId;
        return $this->payloadFromRow($row, false);
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
     * Extends expires_at. Requires token. Revoked licenses cannot be renewed.
     * New expiry must parse as UTC ISO-8601 and be strictly after the current expires_at.
     *
     * @return array<string, mixed> Unsigned payload suitable for Signer::sign (shop pulls via refresh)
     */
    public function renew(string $id, string $token, string $newExpiresAt): array
    {
        $row = $this->requireRow($id, $token);
        if ((int) $row['revoked'] === 1) {
            throw new \DomainException('License revoked.');
        }
        $newExpiresAt = trim($newExpiresAt);
        $newTs = strtotime($newExpiresAt);
        $oldTs = strtotime((string) $row['expires_at']);
        if ($newTs === false || $oldTs === false) {
            throw new \DomainException('Expiry timestamp is not acceptable.');
        }
        if ($newTs <= $oldTs) {
            throw new \DomainException('New expiry must be after the current expiry.');
        }
        $normalized = gmdate('c', $newTs);
        $previous = (string) $row['expires_at'];
        $this->pdo->prepare('UPDATE licenses SET expires_at = :x WHERE id = :i')
            ->execute(['x' => $normalized, 'i' => $id]);
        $this->audit($id, 'renew', [
            'previous_expires_at' => $previous,
            'expires_at' => $normalized,
        ]);
        $row['expires_at'] = $normalized;
        return $this->payloadFromRow($row, false);
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
            'product_code' => (string) ($row['product_code'] ?? ''),
            'product_version' => (string) ($row['product_version'] ?? ''),
            'customer_id' => (string) ($row['customer_id'] ?? ''),
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
            'product_code' => (string) ($row['product_code'] ?? ''),
            'product_version' => (string) ($row['product_version'] ?? ''),
            'customer_id' => (string) ($row['customer_id'] ?? ''),
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

    private function requireProductCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code) !== 1) {
            throw new \InvalidArgumentException('Product code is not acceptable.');
        }
        return $code;
    }

    private function requireCustomerId(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Customer id is not acceptable.');
        }
        return $id;
    }

    private function requireOrderId(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Order id is not acceptable.');
        }
        return $id;
    }

    private function requireVersion(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+([.+-][a-zA-Z0-9._-]+)?$/', $version) !== 1) {
            throw new \InvalidArgumentException('Product version is not acceptable.');
        }
        return $version;
    }

    /**
     * @param list<string>|array<int, mixed> $entitlements
     * @return list<string>
     */
    private function normalizeEntitlements(array $entitlements): array
    {
        $out = [];
        foreach ($entitlements as $ent) {
            $ent = trim((string) $ent);
            if ($ent === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $ent) !== 1) {
                throw new \InvalidArgumentException('Entitlement is not acceptable.');
            }
            $out[] = $ent;
        }
        return array_values(array_unique($out));
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

    private function ensureLicenseProductColumns(): void
    {
        $names = [];
        foreach ($this->pdo->query('PRAGMA table_info(licenses)') as $col) {
            $names[] = (string) $col['name'];
        }
        if (!in_array('product_code', $names, true)) {
            $this->pdo->exec("ALTER TABLE licenses ADD COLUMN product_code TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('product_version', $names, true)) {
            $this->pdo->exec("ALTER TABLE licenses ADD COLUMN product_version TEXT NOT NULL DEFAULT ''");
        }
    }

    private function ensureLicenseCustomerColumn(): void
    {
        $names = [];
        foreach ($this->pdo->query('PRAGMA table_info(licenses)') as $col) {
            $names[] = (string) $col['name'];
        }
        if (!in_array('customer_id', $names, true)) {
            $this->pdo->exec("ALTER TABLE licenses ADD COLUMN customer_id TEXT NOT NULL DEFAULT ''");
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
