<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Kernel;
use IndependentMall\View\Renderer;
use IndependentMall\Http\Router;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Security\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    public function testConfigLoadsExample(): void
    {
        $config = Config::load($this->root);
        $this->assertSame($this->root, $config->root());
        $this->assertNotSame('', $config->get('DATABASE_URL'));
    }

    public function testMigrationsAndAdminLogin(): void
    {
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        $migrator = new Migrator($pdo, $this->root . '/database/migrations');
        $ran = $migrator->migrate();
        $this->assertContains('0001_foundation.sql', $ran);
        $this->assertSame([], $migrator->migrate());

        $hasher = new PasswordHasher();
        $id = (new AdminProvisioner($pdo, $hasher))->create('ops@example.invalid', 'ops', 'correct-horse-battery');
        $this->assertGreaterThan(0, $id);

        $auth = new SessionAuth($pdo, $hasher, new RateLimiter($pdo), new AuditLogger($pdo), 'test-key');
        $dir = sys_get_temp_dir() . '/im-sess-' . bin2hex(random_bytes(4));
        $auth->start($dir, 'im_test', 3600, false);
        $_SESSION = [];
        $this->assertTrue($auth->login('ops@example.invalid', 'correct-horse-battery', '127.0.0.1'));
        $this->assertTrue($auth->can('admin.access'));
        $this->assertFalse($auth->login('ops@example.invalid', 'wrong-password-value', '127.0.0.1'));
    }

    public function testHealthDoesNotRequireLicense(): void
    {
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $this->root . '/database/migrations'))->migrate();
        $hasher = new PasswordHasher();
        $auth = new SessionAuth($pdo, $hasher, new RateLimiter($pdo), new AuditLogger($pdo), 'test-key');
        $config = Config::load($this->root);
        $kernel = new Kernel($config, $pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $health = $kernel->health();
        $this->assertSame('community', $health['edition']);
        $this->assertFalse($health['license_required']);
        $this->assertSame('ok', $health['database']);
    }

    public function testCsrfRejectsMismatch(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $csrf = new Csrf();
        $token = $csrf->token();
        $bad = new Request('POST', '/admin/login', [], ['_csrf' => 'nope'], [], []);
        $this->assertFalse($csrf->assert($bad));
        $good = new Request('POST', '/admin/login', [], ['_csrf' => $token], [], []);
        $this->assertTrue($csrf->assert($good));
    }

    public function testHomeAndHealthRoutes(): void
    {
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $this->root . '/database/migrations'))->migrate();
        $config = Config::load($this->root);
        $hasher = new PasswordHasher();
        $auth = new SessionAuth($pdo, $hasher, new RateLimiter($pdo), new AuditLogger($pdo), 'test-key');
        $kernel = new Kernel($config, $pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());

        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('lawful trade', $home->body());
        $this->assertStringNotContainsString('彩虹', $home->body());

        $health = $kernel->handle(new Request('GET', '/healthz', [], [], [], []));
        $this->assertSame(200, $health->status());
        $this->assertSame('application/json; charset=UTF-8', $health->header('Content-Type'));
        $payload = json_decode($health->body(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['license_required']);
        $this->assertSame('community', $payload['edition']);
    }
}
