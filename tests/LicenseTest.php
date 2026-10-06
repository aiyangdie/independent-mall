<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

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
use IndependentMall\License\AdminRefresh;
use IndependentMall\License\FileLicenseGate;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;
use IndependentMall\Security\AuditLogger;
use IndependentMall\Tests\Support\FrozenClock;
use IndependentMall\Tests\Support\RecordingTransport;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class LicenseTest extends TestCase
{
    public function testSignVerifyTamperRevokeAndGrace(): void
    {
        $pair = Signer::generateKeypair();
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $pair['private']);
        $dir = sys_get_temp_dir() . '/im-lic-' . bin2hex(random_bytes(3));
        mkdir($dir, 0775, true);
        $issued = time();
        $payload = [
            'license_id' => 'lic_1',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $issued),
            'last_refresh_at' => gmdate('c', $issued),
            'expires_at' => gmdate('c', $issued + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        $env = Signer::sign($payload, $pair['private']);
        file_put_contents($dir . '/var-license.json', json_encode($env));
        file_put_contents($dir . '/pub.pem', $pair['public']);
        $gate = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($issued + 10));
        $this->assertTrue($gate->allows(['storefront_notice']));
        $this->assertFalse($gate->allows(['multi_store']));

        $tampered = $env;
        $tampered['payload']['entitlements'] = ['multi_store'];
        file_put_contents($dir . '/var-license.json', json_encode($tampered));
        $bad = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($issued + 10));
        $this->assertFalse($bad->allows(['storefront_notice']));
        $this->assertFalse($bad->allows(['multi_store']));

        $revoked = $payload;
        $revoked['revoked'] = true;
        file_put_contents($dir . '/var-license.json', json_encode(Signer::sign($revoked, $pair['private'])));
        $rev = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($issued + 10));
        $this->assertFalse($rev->allows(['storefront_notice']));

        $gracePayload = $payload;
        file_put_contents($dir . '/var-license.json', json_encode(Signer::sign($gracePayload, $pair['private'])));
        $afterExpiry = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($issued + 3600 + 86400));
        $this->assertTrue($afterExpiry->allows(['storefront_notice']));
        $afterGrace = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($issued + 3600 + 15 * 86400));
        $this->assertFalse($afterGrace->allows(['storefront_notice']));
    }

    public function testServerActivateBindsInstanceAndOfflineDocument(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $store->issue('lic_2', 'secret-token-value', ['storefront_notice'], gmdate('c', time() + 86400));
        $payload = $store->activate('lic_2', 'secret-token-value', 'host-a');
        $this->assertSame('host-a', $payload['instance_id']);
        $this->expectException(\DomainException::class);
        $store->activate('lic_2', 'secret-token-value', 'host-b');
    }

    public function testUnbindAllowsANewInstance(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $store->issue('lic_3', 'token-three-xx', ['storefront_notice'], gmdate('c', time() + 86400));
        $store->activate('lic_3', 'token-three-xx', 'host-a');
        $store->unbind('lic_3');
        $again = $store->activate('lic_3', 'token-three-xx', 'host-b');
        $this->assertSame('host-b', $again['instance_id']);
    }

    public function testFutureIssuedAtBeyondSkewIsRejected(): void
    {
        $pair = Signer::generateKeypair();
        $dir = sys_get_temp_dir() . '/im-lic-skew-' . bin2hex(random_bytes(3));
        mkdir($dir, 0775, true);
        $now = time();
        $payload = [
            'license_id' => 'lic_skew',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $now + 600),
            'last_refresh_at' => gmdate('c', $now + 600),
            'expires_at' => gmdate('c', $now + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        file_put_contents($dir . '/var-license.json', json_encode(Signer::sign($payload, $pair['private'])));
        file_put_contents($dir . '/pub.pem', $pair['public']);
        $gate = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($now));
        $this->assertFalse($gate->allows(['storefront_notice']));
        $payload['issued_at'] = gmdate('c', $now + 60);
        $payload['last_refresh_at'] = gmdate('c', $now + 60);
        file_put_contents($dir . '/var-license.json', json_encode(Signer::sign($payload, $pair['private'])));
        $near = new FileLicenseGate($dir . '/var-license.json', $dir . '/pub.pem', new FrozenClock($now));
        $this->assertTrue($near->allows(['storefront_notice']));
    }

    public function testExpiredLicenseDoesNotBreakStorefront(): void
    {
        $root = dirname(__DIR__);
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $root . '/database/migrations'))->migrate();
        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'k');
        $kernel = new Kernel(Config::load($root), $pdo, $auth, new Csrf(), new Renderer($root . '/templates'), new Router());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('lawful trade', $home->body());
        $health = json_decode($kernel->handle(new Request('GET', '/healthz', [], [], [], []))->body(), true);
        $this->assertFalse($health['license_required']);
        $shopFront = (string) file_get_contents($this->root() . '/src/Http/ShopFront.php');
        $this->assertStringNotContainsString('AdminRefresh', $shopFront);
        $this->assertStringNotContainsString('LICENSE_SERVER_URL', $shopFront);
    }

    public function testAdminRefreshUpdatesDocumentAndFailureKeepsGrace(): void
    {
        $pair = Signer::generateKeypair();
        $dir = sys_get_temp_dir() . '/im-ref-' . bin2hex(random_bytes(3));
        mkdir($dir . '/var', 0775, true);
        $issued = time();
        $oldPayload = [
            'license_id' => 'lic_r',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $issued),
            'last_refresh_at' => gmdate('c', $issued),
            'expires_at' => gmdate('c', $issued + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        $old = Signer::sign($oldPayload, $pair['private']);
        file_put_contents($dir . '/var/license.json', json_encode($old));
        file_put_contents($dir . '/var/license-public.pem', $pair['public']);
        $freshPayload = $oldPayload;
        $freshPayload['last_refresh_at'] = gmdate('c', $issued + 100);
        $ok = new RecordingTransport(Signer::sign($freshPayload, $pair['private']));
        (new AdminRefresh($dir, $ok))->refresh('http://127.0.0.1:8091', 'lic_r', 'tok', 'shop-1');
        $this->assertCount(1, $ok->calls);
        $this->assertStringEndsWith('/refresh', $ok->calls[0]['url']);
        $stored = json_decode((string) file_get_contents($dir . '/var/license.json'), true);
        $this->assertSame($freshPayload['last_refresh_at'], $stored['payload']['last_refresh_at']);

        $fail = new RecordingTransport(null, new \RuntimeException('down'));
        try {
            (new AdminRefresh($dir, $fail))->refresh('http://127.0.0.1:8091', 'lic_r', 'tok', 'shop-1');
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }
        $afterFail = json_decode((string) file_get_contents($dir . '/var/license.json'), true);
        $this->assertSame($freshPayload['last_refresh_at'], $afterFail['payload']['last_refresh_at']);
        $gate = new FileLicenseGate($dir . '/var/license.json', $dir . '/var/license-public.pem', new FrozenClock($issued + 3600 + 86400));
        $this->assertTrue($gate->allows(['storefront_notice']));

        $empty = new RecordingTransport(['ignored' => true]);
        try {
            (new AdminRefresh($dir, $empty))->refresh('', 'lic_r', 'tok', 'shop-1');
            $this->fail('empty url');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        $this->assertSame([], $empty->calls);
    }

    public function testServerRefreshRequiresBoundInstance(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $store->issue('lic_r2', 'token-refresh', ['storefront_notice'], gmdate('c', time() + 86400));
        try {
            $store->refresh('lic_r2', 'token-refresh', 'host-a');
            $this->fail('unbound');
        } catch (\DomainException) {
        }
        $store->activate('lic_r2', 'token-refresh', 'host-a');
        $again = $store->refresh('lic_r2', 'token-refresh', 'host-a');
        $this->assertSame('host-a', $again['instance_id']);
        $this->assertNotSame('', $again['last_refresh_at']);
    }

    public function testNoPrivateKeysInCommunityOrSamplePlugin(): void
    {
        foreach ([$this->root() . '/src', $this->root() . '/modules', $this->root() . '/apps/license-server/src', $this->root() . '/apps/www/src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php' && $file->getExtension() !== 'md' && $file->getExtension() !== 'json') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $code, $file->getPathname());
                $this->assertStringNotContainsString('BEGIN OPENSSH PRIVATE', $code, $file->getPathname());
            }
        }
        $src = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src'));
        foreach ($src as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('IndependentMall\\LicenseServer', $code, $file->getPathname());
        }
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }
}
