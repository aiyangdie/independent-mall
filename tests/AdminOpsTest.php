<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\CustomerAccounts;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use IndependentMall\Config\ShopSettings;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Router;
use IndependentMall\Kernel;
use IndependentMall\Mail\NullMailer;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class AdminOpsTest extends TestCase
{
    private string $root;
    private \PDO $pdo;
    private SessionAuth $auth;
    private Kernel $kernel;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $this->root = dirname(__DIR__);
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, $this->root . '/database/migrations'))->migrate();
        $hasher = new PasswordHasher();
        $this->auth = new SessionAuth($this->pdo, $hasher, new RateLimiter($this->pdo), new AuditLogger($this->pdo), 'k');
        $dir = sys_get_temp_dir() . '/im-admin-' . bin2hex(random_bytes(3));
        $this->auth->start($dir, 'ima', 3600, false);
        $this->kernel = new Kernel($this->config(), $this->pdo, $this->auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
    }

    private function config(): Config
    {
        return Config::load($this->root);
    }

    public function testGuestAdminPagesRedirect(): void
    {
        foreach (['/admin/customers', '/admin/settings', '/admin/skus', '/admin/audit', '/admin/payments', '/admin/refunds'] as $path) {
            $res = $this->kernel->handle(new Request('GET', $path, [], [], [], []));
            $this->assertSame(302, $res->status(), $path);
            $this->assertSame('/admin/login', $res->header('Location'));
        }
    }

    public function testAdminCanManageCustomersSettingsAndSkus(): void
    {
        $hasher = new PasswordHasher();
        (new AdminProvisioner($this->pdo, $hasher))->create('ops@example.invalid', 'ops', 'correct-horse-battery');
        $accounts = new CustomerAccounts($this->pdo, $hasher, new NullMailer(), new AuditLogger($this->pdo), $this->auth);
        $buyer = $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $this->assertTrue($this->auth->login('ops@example.invalid', 'correct-horse-battery', '127.0.0.1'));

        $customers = $this->kernel->handle(new Request('GET', '/admin/customers', [], [], [], []));
        $this->assertSame(200, $customers->status());
        $this->assertStringContainsString('buyer@example.invalid', $customers->body());

        $accounts->setStatus($buyer, 'locked', (int) $this->auth->user()['id']);
        $this->assertFalse($this->auth->login('buyer@example.invalid', 'correct-horse-1', '10.0.0.9'));

        $settings = new ShopSettings($this->pdo, new AuditLogger($this->pdo));
        $settings->put('shop_name', 'North Harbor Goods', 1);
        $home = $this->kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertStringContainsString('North Harbor Goods', $home->body());
        $page = $this->kernel->handle(new Request('GET', '/admin/settings', [], [], [], []));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('North Harbor Goods', $page->body());
        $skus = $this->kernel->handle(new Request('GET', '/admin/skus', [], [], [], []));
        $this->assertSame(200, $skus->status());
        $payments = $this->kernel->handle(new Request('GET', '/admin/payments', [], [], [], []));
        $this->assertSame(200, $payments->status());
        $this->assertStringContainsString('Fake adapter only', $payments->body());
        $refunds = $this->kernel->handle(new Request('GET', '/admin/refunds', [], [], [], []));
        $this->assertSame(200, $refunds->status());
        $this->assertStringContainsString('Refunds', $refunds->body());
    }

    public function testCannotLockAdministratorFromCustomerScreen(): void
    {
        $hasher = new PasswordHasher();
        $adminId = (new AdminProvisioner($this->pdo, $hasher))->create('ops@example.invalid', 'ops', 'correct-horse-battery');
        $accounts = new CustomerAccounts($this->pdo, $hasher, new NullMailer(), new AuditLogger($this->pdo), $this->auth);
        $buyer = $accounts->register('buyer@example.invalid', 'buyer1', 'correct-horse-1');
        $this->expectException(DomainException::class);
        $accounts->setStatus($adminId, 'locked', $buyer);
    }
}
