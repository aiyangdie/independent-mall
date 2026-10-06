<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Router;
use IndependentMall\Kernel;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\Modules\AllowListLicense;
use IndependentMall\Modules\AbsentLicense;
use IndependentMall\Modules\ModuleHost;
use IndependentMall\Security\AuditLogger;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class ModuleHostTest extends TestCase
{
    private string $root;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($this->pdo, $this->root . '/database/migrations'))->migrate();
    }

    public function testBrokenModuleDoesNotStopHost(): void
    {
        $tmp = sys_get_temp_dir() . '/im-mod-' . bin2hex(random_bytes(3));
        mkdir($tmp . '/modules/boom', 0775, true);
        mkdir($tmp . '/modules/ok', 0775, true);
        file_put_contents($tmp . '/modules/boom/module.php', "<?php throw new RuntimeException('boom');\n");
        file_put_contents(
            $tmp . '/modules/ok/module.php',
            "<?php return new IndependentMall\\Tests\\Support\\FakeNoticeModule();\n",
        );
        $host = new ModuleHost($this->pdo, $tmp, new AllowListLicense(['storefront_notice']));
        $this->assertNotSame([], $host->faults());
        $this->assertCount(1, $host->modules());
        $this->assertSame('', $host->storefrontNotice());
        $host->migrate('fake-notice', 1);
        $this->expectException(DomainException::class);
        $denied = new ModuleHost($this->pdo, $tmp, new AbsentLicense());
        $denied->migrate('fake-notice', 1);
        $denied->enable('fake-notice', 1);
    }

    public function testEnableDisableUninstallWithLocalEntitlement(): void
    {
        $tmp = sys_get_temp_dir() . '/im-mod2-' . bin2hex(random_bytes(3));
        mkdir($tmp . '/modules/ok', 0775, true);
        file_put_contents(
            $tmp . '/modules/ok/module.php',
            "<?php return new IndependentMall\\Tests\\Support\\FakeNoticeModule();\n",
        );
        $host = new ModuleHost($this->pdo, $tmp, new AllowListLicense(['storefront_notice']));
        $this->expectException(DomainException::class);
        $host->enable('fake-notice', 1);
    }

    public function testNoticeAppearsOnlyWhenEnabledAndLicensed(): void
    {
        $tmp = sys_get_temp_dir() . '/im-mod3-' . bin2hex(random_bytes(3));
        mkdir($tmp . '/modules/ok', 0775, true);
        file_put_contents(
            $tmp . '/modules/ok/module.php',
            "<?php return new IndependentMall\\Tests\\Support\\FakeNoticeModule();\n",
        );
        $host = new ModuleHost($this->pdo, $tmp, new AllowListLicense(['storefront_notice']));
        $host->migrate('fake-notice', null);
        $host->enable('fake-notice', null);
        $this->assertStringContainsString('Hello from a test module', $host->storefrontNotice());
        $host->disable('fake-notice', null);
        $this->assertSame('', $host->storefrontNotice());
        $host->uninstall('fake-notice', null);
        $this->assertFalse($host->modules()[0]->isMigrated($this->pdo));
    }

    public function testCommunitySourcesDoNotImportCommercialPackage(): void
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/src'));
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('IndependentMall\\Commercial', $code, $file->getPathname());
            $this->assertStringNotContainsString('modules/commercial', $code, $file->getPathname());
        }
    }

    public function testOptionalCommercialSampleLifecycle(): void
    {
        $host = new ModuleHost($this->pdo, $this->root, new AllowListLicense(['storefront_notice']));
        if (!is_file($this->root . '/modules/commercial/module.php')) {
            $this->assertSame([], $host->modules());
            return;
        }
        $ids = array_map(static fn ($m) => $m->id(), $host->modules());
        $this->assertContains('sample-notice', $ids);
        $host->migrate('sample-notice', null);
        $host->enable('sample-notice', null);
        $this->assertStringContainsString('社区版结账仍可正常使用', $host->storefrontNotice());
        $host->disable('sample-notice', null);
        $this->assertSame('', $host->storefrontNotice());
        $host->uninstall('sample-notice', null);
        $absent = new ModuleHost($this->pdo, $this->root, new AbsentLicense());
        $this->expectException(DomainException::class);
        $absent->enable('sample-notice', null);
    }

    public function testKernelHomeSurvivesModuleDiscovery(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $auth = new SessionAuth($this->pdo, new PasswordHasher(), new RateLimiter($this->pdo), new AuditLogger($this->pdo), 'k');
        $kernel = new Kernel(Config::load($this->root), $this->pdo, $auth, new Csrf(), new Renderer($this->root . '/templates'), new Router());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('正规商品', $home->body());
        $this->assertStringNotContainsString('示例商业公告', $home->body());
        $health = $kernel->handle(new Request('GET', '/healthz', [], [], [], []));
        $payload = json_decode($health->body(), true);
        $this->assertFalse($payload['license_required']);
        $this->assertFalse($payload['network_required']);
        $this->assertSame('absent', $payload['license']['mode'] ?? null);
        $this->assertFalse($payload['license']['valid'] ?? true);
    }

    public function testKernelEnablesSampleNoticeFromSignedLocalLicense(): void
    {
        if (!is_file($this->root . '/modules/commercial/module.php')) {
            $this->markTestSkipped('sample commercial tree is optional');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $tmp = sys_get_temp_dir() . '/im-fgate-' . bin2hex(random_bytes(3));
        mkdir($tmp . '/var', 0775, true);
        mkdir($tmp . '/modules/commercial/src', 0775, true);
        foreach (['module.php', 'autoload.php', 'README.md'] as $name) {
            copy($this->root . '/modules/commercial/' . $name, $tmp . '/modules/commercial/' . $name);
        }
        copy(
            $this->root . '/modules/commercial/src/SampleNoticeModule.php',
            $tmp . '/modules/commercial/src/SampleNoticeModule.php',
        );
        file_put_contents($tmp . '/.env', "APP_KEY=test-file-gate\nLICENSE_SERVER_URL=\n");
        $auth = new SessionAuth($this->pdo, new PasswordHasher(), new RateLimiter($this->pdo), new AuditLogger($this->pdo), 'k');
        $auth->start(sys_get_temp_dir() . '/im-fgate-sess-' . bin2hex(random_bytes(3)), 'imf', 3600, false);
        $csrf = new Csrf();
        $token = $csrf->token();
        $kernel = new Kernel(Config::load($tmp), $this->pdo, $auth, $csrf, new Renderer($this->root . '/templates'), new Router());
        (new AdminProvisioner($this->pdo, new PasswordHasher()))->create('ops@example.invalid', 'ops', 'correct-horse-battery');
        $this->assertTrue($auth->login('ops@example.invalid', 'correct-horse-battery', '127.0.0.1'));

        $denied = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $token], [], []));
        $this->assertSame(422, $denied->status());
        $this->assertStringContainsString('entitlement', $denied->body());

        $pair = Signer::generateKeypair();
        $now = time();
        $envelope = Signer::sign([
            'license_id' => 'lic_kernel',
            'instance_id' => 'shop-test',
            'entitlements' => ['storefront_notice'],
            'product_code' => 'sample-notice',
            'product_version' => '1.0.0',
            'customer_id' => 'cust_demo',
            'issued_at' => gmdate('c', $now),
            'last_refresh_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + 86400),
            'grace_days' => 14,
            'revoked' => false,
        ], $pair['private']);
        $trust = $kernel->handle(new Request('POST', '/admin/license/trust-key', [], [
            '_csrf' => $token,
            'public_pem' => $pair['public'],
        ], [], []));
        $this->assertSame(302, $trust->status());
        $stored = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $token,
            'license_json' => (string) json_encode($envelope),
        ], [], []));
        $this->assertSame(302, $stored->status());
        $this->assertSame('/admin/modules', $stored->header('Location'));

        $migrate = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/migrate', [], ['_csrf' => $token], [], []));
        $this->assertSame(302, $migrate->status());
        $enable = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $token], [], []));
        $this->assertSame(302, $enable->status());
        $page = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertStringContainsString('本地授权：是', $page->body());
        $this->assertStringContainsString('lic_kernel', $page->body());
        $this->assertStringContainsString('shop-test', $page->body());
        $this->assertStringContainsString('绑定实例', $page->body());
        $this->assertStringContainsString('sample-notice@1.0.0', $page->body());
        $this->assertStringContainsString('客户编号', $page->body());
        $this->assertStringContainsString('cust_demo', $page->body());
        $audit = $this->pdo->query("SELECT action, entity_id, meta FROM audit_logs WHERE action = 'license.store'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($audit);
        $this->assertSame('lic_kernel', $audit['entity_id']);
        $this->assertStringContainsString('shop-test', (string) $audit['meta']);
        $this->assertStringNotContainsString($pair['private'], (string) $audit['meta']);
        $filtered = $kernel->handle(new Request('GET', '/admin/audit', ['action' => 'license.'], [], [], []));
        $this->assertSame(200, $filtered->status());
        $this->assertStringContainsString('license.store', $filtered->body());
        $this->assertStringContainsString('name="action"', $filtered->body());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('示例商业公告', $home->body());
        $this->assertStringContainsString('正规商品', $home->body());
        $health = json_decode($kernel->handle(new Request('GET', '/healthz', [], [], [], []))->body(), true);
        $this->assertFalse($health['license_required']);
        $this->assertFalse($health['network_required']);
        $this->assertSame('offline_file', $health['license']['mode'] ?? null);
        $this->assertTrue($health['license']['valid'] ?? false);
        $this->assertContains('storefront_notice', $health['license']['entitlements'] ?? []);
        $this->assertArrayNotHasKey('token', $health);
        $this->assertArrayNotHasKey('nonce', $health);

        $revoked = Signer::sign([
            'license_id' => 'lic_kernel',
            'instance_id' => 'shop-test',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $now),
            'last_refresh_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + 86400),
            'grace_days' => 14,
            'revoked' => true,
        ], $pair['private']);
        $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $token,
            'license_json' => (string) json_encode($revoked),
        ], [], []));
        $stopped = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertStringContainsString('正规商品', $stopped->body());
        $this->assertStringNotContainsString('示例商业公告', $stopped->body());
        $mods = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertStringContainsString('已吊销', $mods->body());
        $this->assertStringContainsString('社区版下单不受影响', $mods->body());

        $kernel->handle(new Request('POST', '/admin/modules/sample-notice/disable', [], ['_csrf' => $token], [], []));
        $off = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertStringNotContainsString('示例商业公告', $off->body());
    }
}
