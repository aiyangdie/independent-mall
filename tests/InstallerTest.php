<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Router;
use IndependentMall\Install\Environment;
use IndependentMall\Install\Installer;
use IndependentMall\Kernel;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $root;
    private string $tmp;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $this->root = dirname(__DIR__);
        $this->tmp = sys_get_temp_dir() . '/im-install-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/database/migrations', 0775, true);
        mkdir($this->tmp . '/var', 0775, true);
        copy($this->root . '/.env.example', $this->tmp . '/.env.example');
        foreach (glob($this->root . '/database/migrations/*.sql') ?: [] as $file) {
            copy($file, $this->tmp . '/database/migrations/' . basename($file));
        }
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tmp);
    }

    public function testEnvironmentChecksPassHere(): void
    {
        $checks = Environment::checks($this->tmp);
        $this->assertTrue(Environment::allOk($checks));
    }

    public function testFreshInstallThenRefuseOverwrite(): void
    {
        $installer = new Installer($this->tmp, $this->tmp . '/database/migrations');
        $this->assertFalse($installer->isInstalled());
        $installer->install([
            'admin_email' => 'ops@example.invalid',
            'admin_username' => 'ops',
            'admin_password' => 'correct-horse-battery',
            'shop_name' => 'Harbor Shop',
            'app_url' => 'http://127.0.0.1:8090',
            'database_url' => 'sqlite:///%ROOT%/var/data/app.sqlite',
            'seed' => '1',
        ]);
        $this->assertTrue($installer->isInstalled());
        $this->assertFileExists($this->tmp . '/.env');
        $this->assertFileExists($this->tmp . '/var/installed.json');
        $env = file_get_contents($this->tmp . '/.env');
        $this->assertIsString($env);
        $this->assertStringNotContainsString('CHANGE_ME', $env);
        $this->assertStringNotContainsString('correct-horse-battery', $env);
        $lock = json_decode((string) file_get_contents($installer->lockPath()), true);
        $this->assertSame('community', $lock['edition']);
        $this->assertMatchesRegularExpression('/^ADMIN_PASSWORD=$/m', $env);
        $migrator = new \IndependentMall\Database\Migrator(
            \IndependentMall\Database\ConnectionFactory::make(\IndependentMall\Config\Config::load($this->tmp)->get('DATABASE_URL')),
            $this->tmp . '/database/migrations',
        );
        $this->assertSame([], $migrator->migrate());
        file_put_contents($this->tmp . '/database/migrations/9999_release_ops.sql', "CREATE TABLE IF NOT EXISTS release_ops_probe (id INTEGER PRIMARY KEY);\n");
        $this->assertSame(['9999_release_ops.sql'], $migrator->migrate());
        $this->expectException(DomainException::class);
        $installer->install([
            'admin_email' => 'other@example.invalid',
            'admin_username' => 'ops2',
            'admin_password' => 'correct-horse-battery',
            'app_url' => 'http://127.0.0.1:8090',
        ]);
    }

    public function testSnapshotBackupAndRestore(): void
    {
        $installer = new Installer($this->tmp, $this->tmp . '/database/migrations');
        $installer->install([
            'admin_email' => 'ops@example.invalid',
            'admin_password' => 'correct-horse-battery',
            'app_url' => 'http://127.0.0.1:8090',
            'database_url' => 'sqlite:///%ROOT%/var/data/app.sqlite',
        ]);
        file_put_contents($this->tmp . '/var/uploads/marker.txt', 'keep-me');
        $snap = new \IndependentMall\Install\Snapshot($this->tmp);
        $dir = $snap->create();
        $this->assertFileExists($dir . '/MANIFEST.json');
        unlink($this->tmp . '/var/data/app.sqlite');
        unlink($this->tmp . '/var/uploads/marker.txt');
        $snap->restore($dir);
        $this->assertFileExists($this->tmp . '/var/data/app.sqlite');
        $this->assertSame('keep-me', (string) file_get_contents($this->tmp . '/var/uploads/marker.txt'));
        $this->expectException(\InvalidArgumentException::class);
        $snap->restore($this->tmp);
    }

    public function testReleaseScanIsCleanOnCommunityTree(): void
    {
        $hits = \IndependentMall\Install\ReleaseScan::findings($this->root);
        $this->assertSame([], $hits, json_encode($hits));
    }

    public function testUninstalledKernelRedirectsAndLocksAfterConfig(): void
    {
        $config = Config::load($this->tmp);
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'k');
        $dir = sys_get_temp_dir() . '/im-isess-' . bin2hex(random_bytes(3));
        $auth->start($dir, 'imi', 3600, false);
        $kernel = new Kernel($config, $pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(302, $home->status());
        $this->assertSame('/install', $home->header('Location'));
        $form = $kernel->handle(new Request('GET', '/install', [], [], [], []));
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('Install Community Edition', $form->body());
        $health = $kernel->handle(new Request('GET', '/healthz', [], [], [], []));
        $this->assertSame(200, $health->status());
        $payload = json_decode($health->body(), true);
        $this->assertFalse($payload['installed']);
        $this->assertFalse($payload['license_required']);
        $this->assertFalse($payload['network_required']);
    }

    public function testConfiguredInstanceShowsLockedInstaller(): void
    {
        file_put_contents($this->tmp . '/.env', "APP_KEY=" . bin2hex(random_bytes(16)) . "\n");
        file_put_contents($this->tmp . '/var/installed.json', '{"edition":"community"}');
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'k');
        $kernel = new Kernel(Config::load($this->tmp), $pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $page = $kernel->handle(new Request('GET', '/install', [], [], [], []));
        $this->assertSame(409, $page->status());
        $this->assertStringContainsString('Already installed', $page->body());
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
