<?php

declare(strict_types=1);

namespace IndependentMall\Install;

use DomainException;
use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Catalog\DemoSeeder;
use IndependentMall\Config\Config;
use IndependentMall\Config\ShopSettings;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Security\AuditLogger;

final class Installer
{
    public function __construct(
        private string $root,
        private ?string $migrationsDir = null,
    ) {
        $this->migrationsDir ??= $root . '/database/migrations';
    }

    public function lockPath(): string
    {
        return $this->root . '/var/installed.json';
    }

    public function isInstalled(): bool
    {
        if (is_file($this->lockPath())) {
            return true;
        }
        $env = $this->root . '/.env';
        if (!is_file($env)) {
            return false;
        }
        $key = Config::load($this->root)->get('APP_KEY');
        return $key !== '' && $key !== 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' && $key !== 'CHANGE_ME';
    }

    /**
     * @param array<string, string> $input
     */
    public function install(array $input): void
    {
        if ($this->isInstalled()) {
            throw new DomainException('This instance is already installed.');
        }
        if (is_file($this->root . '/.env')) {
            throw new DomainException('A .env file already exists. Refusing to overwrite it.');
        }
        $checks = Environment::checks($this->root);
        if (!Environment::allOk($checks)) {
            throw new DomainException('Environment checks failed.');
        }
        $email = mb_strtolower(trim($input['admin_email'] ?? ''));
        $username = trim($input['admin_username'] ?? 'admin');
        $password = $input['admin_password'] ?? '';
        $shop = trim($input['shop_name'] ?? '独立商城');
        $appUrl = trim($input['app_url'] ?? 'http://127.0.0.1:8090');
        $databaseUrl = trim($input['database_url'] ?? 'sqlite:///%ROOT%/var/data/app.sqlite');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('A valid administrator email is required.');
        }
        if (!preg_match('#^https?://#', $appUrl)) {
            throw new DomainException('APP_URL must start with http:// or https://');
        }
        $hasher = new PasswordHasher();
        $hasher->assertStrong($password);
        $appKey = bin2hex(random_bytes(32));
        $this->writeEnv([
            'APP_ENV' => 'production',
            'APP_DEBUG' => '0',
            'APP_URL' => $appUrl,
            'APP_KEY' => $appKey,
            'DATABASE_URL' => $databaseUrl,
            'ADMIN_EMAIL' => $email,
            'ADMIN_PASSWORD' => '',
            'MAIL_ADAPTER' => 'null',
            'PAYMENT_ADAPTER' => 'fake',
            'LICENSE_SERVER_URL' => '',
        ]);
        $config = Config::load($this->root);
        $pdo = ConnectionFactory::make($config->get('DATABASE_URL'));
        $ran = (new Migrator($pdo, $this->migrationsDir))->migrate();
        if ($ran === []) {
            throw new DomainException('No migrations were applied; aborting incomplete install.');
        }
        (new AdminProvisioner($pdo, $hasher))->create($email, $username === '' ? 'admin' : $username, $password);
        $settings = new ShopSettings($pdo, new AuditLogger($pdo));
        $settings->put('shop_name', $shop === '' ? '独立商城' : $shop, null);
        if (($input['seed'] ?? '') === '1') {
            (new DemoSeeder($pdo))->seedIfEmpty();
        }
        $payload = json_encode([
            'installed_at' => gmdate('c'),
            'edition' => 'community',
            'php' => PHP_VERSION,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($this->lockPath(), $payload) === false) {
            throw new DomainException('Could not write the install lock file.');
        }
    }

    /**
     * @param array<string, string> $overrides
     */
    private function writeEnv(array $overrides): void
    {
        $values = Config::load($this->root);
        $keys = [
            'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_TIMEZONE', 'APP_CURRENCY', 'APP_LOCALE',
            'DATABASE_URL', 'SESSION_NAME', 'SESSION_SECURE', 'SESSION_LIFETIME',
            'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'MAIL_ADAPTER', 'PAYMENT_ADAPTER', 'STORAGE_ADAPTER',
            'OAUTH_ADAPTER', 'QQ_OAUTH_ENABLED', 'QQ_OAUTH_APP_ID', 'QQ_OAUTH_APP_KEY', 'QQ_OAUTH_REDIRECT_URI',
            'LICENSE_SERVER_URL',
        ];
        $lines = [
            '# Written by Independent Mall installer. Do not commit this file.',
        ];
        foreach ($keys as $key) {
            $val = $overrides[$key] ?? $values->get($key, '');
            $lines[] = $key . '=' . $this->envValue($val);
        }
        $path = $this->root . '/.env';
        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new DomainException('Could not write .env');
        }
    }

    private function envValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#\\\\]/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }
        return $value;
    }
}
