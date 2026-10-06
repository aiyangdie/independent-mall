<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use DomainException;
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
        $this->assertStringContainsString('community checkout still works', $host->storefrontNotice());
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
        $this->assertStringContainsString('lawful trade', $home->body());
        $this->assertStringNotContainsString('Sample commercial notice', $home->body());
        $health = $kernel->handle(new Request('GET', '/healthz', [], [], [], []));
        $payload = json_decode($health->body(), true);
        $this->assertFalse($payload['license_required']);
        $this->assertFalse($payload['network_required']);
    }
}
