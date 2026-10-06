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
        $nonce = $store->issue('lic_2', 'secret-token-value', ['storefront_notice'], gmdate('c', time() + 86400));
        $payload = $store->activate('lic_2', 'secret-token-value', 'host-a', $nonce);
        $this->assertSame('host-a', $payload['instance_id']);
        $this->expectException(\DomainException::class);
        $store->activate('lic_2', 'secret-token-value', 'host-b', $nonce);
    }

    public function testUnbindAllowsANewInstance(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_3', 'token-three-xx', ['storefront_notice'], gmdate('c', time() + 86400));
        $store->activate('lic_3', 'token-three-xx', 'host-a', $nonce);
        $next = $store->unbind('lic_3');
        $this->assertNotSame($nonce, $next);
        try {
            $store->activate('lic_3', 'token-three-xx', 'host-b', $nonce);
            $this->fail('old nonce must not bind after unbind');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('nonce', $e->getMessage());
        }
        $again = $store->activate('lic_3', 'token-three-xx', 'host-b', $next);
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
        $this->assertFalse($health['network_required']);
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

    public function testAdminRefreshRejectsInstanceMismatchWithoutHttp(): void
    {
        $pair = Signer::generateKeypair();
        $dir = sys_get_temp_dir() . '/im-ref-mis-' . bin2hex(random_bytes(3));
        mkdir($dir . '/var', 0775, true);
        $issued = time();
        $payload = [
            'license_id' => 'lic_r',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $issued),
            'last_refresh_at' => gmdate('c', $issued),
            'expires_at' => gmdate('c', $issued + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        file_put_contents($dir . '/var/license.json', json_encode(Signer::sign($payload, $pair['private'])));
        file_put_contents($dir . '/var/license-public.pem', $pair['public']);
        $before = (string) file_get_contents($dir . '/var/license.json');
        $transport = new RecordingTransport(Signer::sign($payload, $pair['private']));
        try {
            (new AdminRefresh($dir, $transport))->refresh('http://127.0.0.1:8091', 'lic_r', 'tok', 'shop-other');
            $this->fail('mismatch');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('instance', $e->getMessage());
        }
        $this->assertSame([], $transport->calls);
        $this->assertSame($before, (string) file_get_contents($dir . '/var/license.json'));
    }

    public function testAdminRefreshKeepsLocalFileWhenServerReturnsOtherInstance(): void
    {
        $pair = Signer::generateKeypair();
        $dir = sys_get_temp_dir() . '/im-ref-swap-' . bin2hex(random_bytes(3));
        mkdir($dir . '/var', 0775, true);
        $issued = time();
        $localPayload = [
            'license_id' => 'lic_r',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $issued),
            'last_refresh_at' => gmdate('c', $issued),
            'expires_at' => gmdate('c', $issued + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        $local = Signer::sign($localPayload, $pair['private']);
        file_put_contents($dir . '/var/license.json', json_encode($local));
        file_put_contents($dir . '/var/license-public.pem', $pair['public']);
        $foreign = $localPayload;
        $foreign['instance_id'] = 'shop-2';
        $foreign['last_refresh_at'] = gmdate('c', $issued + 50);
        $transport = new RecordingTransport(Signer::sign($foreign, $pair['private']));
        try {
            (new AdminRefresh($dir, $transport))->refresh('http://127.0.0.1:8091', 'lic_r', 'tok', 'shop-1');
            $this->fail('foreign instance');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('instance', $e->getMessage());
        }
        $this->assertCount(1, $transport->calls);
        $kept = json_decode((string) file_get_contents($dir . '/var/license.json'), true);
        $this->assertSame('shop-1', $kept['payload']['instance_id']);
        $this->assertSame($localPayload['last_refresh_at'], $kept['payload']['last_refresh_at']);
    }

    public function testServerRefreshRequiresBoundInstance(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_r2', 'token-refresh', ['storefront_notice'], gmdate('c', time() + 86400));
        try {
            $store->refresh('lic_r2', 'token-refresh', 'host-a');
            $this->fail('unbound');
        } catch (\DomainException) {
        }
        $store->activate('lic_r2', 'token-refresh', 'host-a', $nonce);
        $again = $store->refresh('lic_r2', 'token-refresh', 'host-a');
        $this->assertSame('host-a', $again['instance_id']);
        $this->assertNotSame('', $again['last_refresh_at']);
    }

    public function testActivateNonceIsSingleUse(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_once', 'token-once-xxxx', ['storefront_notice'], gmdate('c', time() + 86400));
        $store->activate('lic_once', 'token-once-xxxx', 'host-a', $nonce);
        $this->expectException(\DomainException::class);
        $store->activate('lic_once', 'token-once-xxxx', 'host-a', $nonce);
    }

    public function testRevokeStopsActivateAndRefreshReturnsRevokedPayload(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_rev', 'token-revoke-xx', ['storefront_notice'], gmdate('c', time() + 86400));
        $store->activate('lic_rev', 'token-revoke-xx', 'host-a', $nonce);
        $revoked = $store->revokeIfAuthorized('lic_rev', 'token-revoke-xx');
        $this->assertTrue($revoked['revoked']);
        $this->assertSame('host-a', $revoked['instance_id']);
        $pulled = $store->refresh('lic_rev', 'token-revoke-xx', 'host-a');
        $this->assertTrue($pulled['revoked']);
        $this->expectException(\DomainException::class);
        $store->activate('lic_rev', 'token-revoke-xx', 'host-b', $nonce);
    }

    public function testAdminRefreshStoresRevokedDocument(): void
    {
        $pair = Signer::generateKeypair();
        $dir = sys_get_temp_dir() . '/im-ref-rev-' . bin2hex(random_bytes(3));
        mkdir($dir . '/var', 0775, true);
        $issued = time();
        $payload = [
            'license_id' => 'lic_r',
            'instance_id' => 'shop-1',
            'entitlements' => ['storefront_notice'],
            'issued_at' => gmdate('c', $issued),
            'last_refresh_at' => gmdate('c', $issued),
            'expires_at' => gmdate('c', $issued + 3600),
            'grace_days' => 14,
            'revoked' => false,
        ];
        file_put_contents($dir . '/var/license.json', json_encode(Signer::sign($payload, $pair['private'])));
        file_put_contents($dir . '/var/license-public.pem', $pair['public']);
        $revoked = $payload;
        $revoked['revoked'] = true;
        $revoked['last_refresh_at'] = gmdate('c', $issued + 10);
        $ok = new RecordingTransport(Signer::sign($revoked, $pair['private']));
        (new AdminRefresh($dir, $ok))->refresh('http://127.0.0.1:8091', 'lic_r', 'tok', 'shop-1');
        $gate = new FileLicenseGate($dir . '/var/license.json', $dir . '/var/license-public.pem', new FrozenClock($issued + 20));
        $this->assertFalse($gate->allows(['storefront_notice']));
        $stored = $gate->payload();
        $this->assertIsArray($stored);
        $this->assertTrue(!empty($stored['revoked']));
    }

    public function testActivateRejectsBlankInstanceId(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_blank', 'token-blank-xx', ['storefront_notice'], gmdate('c', time() + 86400));
        $this->expectException(\DomainException::class);
        $store->activate('lic_blank', 'token-blank-xx', '  ', $nonce);
    }

    public function testPurgeRemovesLicenseAndAudit(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_purge', 'token-purge-xx', ['storefront_notice'], gmdate('c', time() + 86400));
        $store->activate('lic_purge', 'token-purge-xx', 'host-a', $nonce);
        $this->assertTrue($store->exists('lic_purge'));
        $store->purge('lic_purge', 'token-purge-xx');
        $this->assertFalse($store->exists('lic_purge'));
        $n = (int) $pdo->query("SELECT COUNT(*) FROM license_audit WHERE license_id = 'lic_purge'")->fetchColumn();
        $this->assertSame(0, $n);
        try {
            $store->purge('lic_purge', 'token-purge-xx');
            $this->fail('already purged');
        } catch (\DomainException) {
        }
    }

    public function testProductCatalogIssueAndRejectsUnknownVersion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $this->assertSame([], $store->catalog());
        $store->registerProduct('sample-notice', 'Sample notice module', ['storefront_notice']);
        $store->registerVersion('sample-notice', '1.0.0', 'Initial demo SKU');
        $catalog = $store->catalog();
        $this->assertCount(1, $catalog);
        $this->assertSame('sample-notice', $catalog[0]['code']);
        $this->assertSame(['storefront_notice'], $catalog[0]['entitlements']);
        $this->assertSame('1.0.0', $catalog[0]['versions'][0]['version']);
        try {
            $store->issueForProduct('lic_cat', 'token-catalog-x', 'sample-notice', '9.9.9', gmdate('c', time() + 86400));
            $this->fail('unknown version must fail');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Unknown product version', $e->getMessage());
        }
        $nonce = $store->issueForProduct(
            'lic_cat',
            'token-catalog-x',
            'sample-notice',
            '1.0.0',
            gmdate('c', time() + 86400),
        );
        $payload = $store->activate('lic_cat', 'token-catalog-x', 'shop-cat-1', $nonce);
        $this->assertSame('sample-notice', $payload['product_code']);
        $this->assertSame('1.0.0', $payload['product_version']);
        $this->assertSame(['storefront_notice'], $payload['entitlements']);
        $status = $store->status('lic_cat');
        $this->assertSame('sample-notice', $status['product_code']);
        $this->assertSame('1.0.0', $status['product_version']);
        $this->assertSame('issue', $status['recent_audit'][1]['action'] ?? $status['recent_audit'][0]['action']);
        $issueMeta = null;
        foreach ($status['recent_audit'] as $entry) {
            if (($entry['action'] ?? '') === 'issue') {
                $issueMeta = $entry['meta'];
                break;
            }
        }
        $this->assertIsArray($issueMeta);
        $this->assertSame('sample-notice', $issueMeta['product_code'] ?? null);
        $this->assertSame('1.0.0', $issueMeta['product_version'] ?? null);
        try {
            $store->registerProduct('Bad Code', 'x', ['storefront_notice']);
            $this->fail('bad product code');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testRenewExtendsExpiryAndRejectsRevokedOrShorter(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $old = gmdate('c', time() + 86400);
        $nonce = $store->issue('lic_rnw', 'token-renew-xx', ['storefront_notice'], $old);
        $store->activate('lic_rnw', 'token-renew-xx', 'host-a', $nonce);
        $later = gmdate('c', time() + 10 * 86400);
        $payload = $store->renew('lic_rnw', 'token-renew-xx', $later);
        $this->assertFalse($payload['revoked']);
        $this->assertSame(strtotime($later), strtotime((string) $payload['expires_at']));
        $status = $store->status('lic_rnw');
        $this->assertSame(strtotime($later), strtotime((string) $status['expires_at']));
        $this->assertSame('renew', $status['recent_audit'][0]['action']);
        $this->assertSame(strtotime($old), strtotime((string) ($status['recent_audit'][0]['meta']['previous_expires_at'] ?? '')));
        $refreshed = $store->refresh('lic_rnw', 'token-renew-xx', 'host-a');
        $this->assertSame(strtotime($later), strtotime((string) $refreshed['expires_at']));
        try {
            $store->renew('lic_rnw', 'token-renew-xx', $old);
            $this->fail('shorter expiry must fail');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('after the current expiry', $e->getMessage());
        }
        $store->revoke('lic_rnw');
        $this->expectException(\DomainException::class);
        $store->renew('lic_rnw', 'token-renew-xx', gmdate('c', time() + 20 * 86400));
    }

    public function testStatusIsReadOnlyWithoutSecrets(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store = new Store($pdo);
        $nonce = $store->issue('lic_st', 'token-status-x', ['storefront_notice'], gmdate('c', time() + 86400));
        $before = $store->status('lic_st');
        $this->assertFalse($before['bound']);
        $this->assertTrue($before['has_activation_nonce']);
        $this->assertFalse($before['revoked']);
        $this->assertSame(['storefront_notice'], $before['entitlements']);
        $encoded = json_encode($before);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('token-status-x', $encoded);
        $this->assertStringNotContainsString($nonce, $encoded);
        $this->assertArrayNotHasKey('token_hash', $before);
        $store->activate('lic_st', 'token-status-x', 'shop-status-1', $nonce);
        $after = $store->status('lic_st');
        $this->assertTrue($after['bound']);
        $this->assertSame('shop-status-1', $after['instance_id']);
        $this->assertFalse($after['has_activation_nonce']);
        $this->assertNotSame([], $after['recent_audit']);
        $this->assertSame('activate', $after['recent_audit'][0]['action']);
        $this->assertSame('shop-status-1', $after['recent_audit'][0]['meta']['instance_id'] ?? null);
        $auditJson = json_encode($after['recent_audit']);
        $this->assertIsString($auditJson);
        $this->assertStringNotContainsString('token-status-x', $auditJson);
        $this->assertStringNotContainsString($nonce, $auditJson);
    }

    public function testLicenseServerLoadsFromItsOwnBootstrap(): void
    {
        $root = $this->root() . '/apps/license-server';
        $boot = (string) file_get_contents($root . '/bootstrap.php');
        $this->assertStringContainsString("'/vendor/autoload.php'", $boot);
        $this->assertStringNotContainsString('IndependentMall\\Kernel', $boot);
        $http = (string) file_get_contents($root . '/public/index.php');
        $this->assertStringContainsString("bootstrap.php", $http);
        $this->assertStringContainsString('/revoke', $http);
        $this->assertStringContainsString('/renew', $http);
        $this->assertStringContainsString('/purge', $http);
        $this->assertStringContainsString('/products', $http);
        $this->assertStringNotContainsString('IndependentMall\\Kernel', $http);
        $this->assertFileExists($this->root() . '/docs/license-server-data.md');
        $dataDoc = (string) file_get_contents($this->root() . '/docs/license-server-data.md');
        $this->assertStringContainsString('token_hash', $dataDoc);
        $this->assertStringContainsString('product_code', $dataDoc);
        $this->assertStringContainsString('Not collected', $dataDoc);
        $this->assertStringContainsString('purge', $dataDoc);
        $this->assertStringNotContainsString('eval(', $http);
        require $root . '/bootstrap.php';
        $this->assertTrue(class_exists(Signer::class));
        $this->assertTrue(class_exists(Store::class));
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
