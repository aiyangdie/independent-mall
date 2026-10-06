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
        $this->assertFalse($health['network_required']);
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
        $this->assertStringContainsString('/legal/refunds', $home->body());
        $this->assertStringContainsString('/legal/prohibited', $home->body());
        $this->assertStringContainsString('aria-label="Legal"', $home->body());
        foreach (['/legal/refunds', '/legal/prohibited', '/legal/disclaimer'] as $path) {
            $legal = $kernel->handle(new Request('GET', $path, [], [], [], []));
            $this->assertSame(200, $legal->status(), $path);
            $this->assertStringContainsString('Draft only', $legal->body());
            $this->assertStringContainsString('Counsel has not reviewed', $legal->body());
            $this->assertStringNotContainsString('代刷', $legal->body());
        }
        $ban = $kernel->handle(new Request('GET', '/legal/prohibited', [], [], [], []));
        $this->assertStringContainsString('account trading', $ban->body());

        $health = $kernel->handle(new Request('GET', '/healthz', [], [], [], []));
        $this->assertSame(200, $health->status());
        $this->assertSame('application/json; charset=UTF-8', $health->header('Content-Type'));
        $payload = json_decode($health->body(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['license_required']);
        $this->assertFalse($payload['network_required']);
        $this->assertSame('community', $payload['edition']);
        $this->assertArrayHasKey('installed', $payload);
    }

    public function testRegisterRequiresTermsAcceptance(): void
    {
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $this->root . '/database/migrations'))->migrate();
        $config = Config::load($this->root);
        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'test-key');
        $sess = sys_get_temp_dir() . '/im-reg-' . bin2hex(random_bytes(3));
        $auth->start($sess, 'imr', 3600, false);
        $csrf = new Csrf();
        $token = $csrf->token();
        $kernel = new Kernel($config, $pdo, $auth, $csrf, new Renderer($this->root . '/templates'), new Router());
        $form = $kernel->handle(new Request('GET', '/register', [], [], [], []));
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('accept_terms', $form->body());
        $this->assertStringContainsString('/legal/disclaimer', $form->body());
        $this->assertStringContainsString('/legal/prohibited', $form->body());
        $denied = $kernel->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'newbie@example.invalid',
            'username' => 'newbie1',
            'password' => 'correct-horse-1',
        ], [], []));
        $this->assertSame(422, $denied->status());
        $this->assertStringContainsString('Accept the draft terms', $denied->body());
        $ok = $kernel->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'newbie@example.invalid',
            'username' => 'newbie1',
            'password' => 'correct-horse-1',
            'accept_terms' => '1',
        ], [], []));
        $this->assertSame(302, $ok->status());
        $this->assertSame('/account', $ok->header('Location'));
    }
}
