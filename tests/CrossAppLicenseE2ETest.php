<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Auth\AdminProvisioner;
use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Auth\RateLimiter;
use IndependentMall\Auth\SessionAuth;
use IndependentMall\Catalog\DemoSeeder;
use IndependentMall\Config\Config;
use IndependentMall\Database\ConnectionFactory;
use IndependentMall\Database\Migrator;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\Http\Router;
use IndependentMall\Kernel;
use IndependentMall\License\FileLicenseGate;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;
use IndependentMall\OfficialSite\Hub;
use IndependentMall\OfficialSite\Site;
use IndependentMall\Security\AuditLogger;
use IndependentMall\Tests\Support\FrozenClock;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * OfficialSite Fake buy → license download → Community offline import → enable sample-notice.
 * Isolated temps; Fake issuer only; no network / real payments.
 */
final class CrossAppLicenseE2ETest extends TestCase
{
    private string $repoRoot;
    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        foreach ($this->temps as $dir) {
            $this->rmTree($dir);
        }
    }

    public function testWwwDownloadToCommunityImportEnableAndNegatives(): void
    {
        $pair = Signer::generateKeypair();
        $privPem = $pair['private'];
        $pubPem = $pair['public'];

        // --- Issuer / OfficialSite ---
        $pdoHub = new \PDO('sqlite::memory:');
        $pdoHub->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $hub = new Hub(
            $pdoHub,
            new PasswordHasher(),
            new Store($pdoHub),
            'hmac-e2e-key-not-for-production',
            $this->repoRoot . '/apps/www/data/files',
            $privPem,
            $pubPem,
        );
        $site = Site::forTests($hub, new Csrf(), $this->repoRoot . '/apps/www/templates');
        $csrf = new Csrf();
        $token = $csrf->token();

        $reg = $site->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'buyer-e2e@example.invalid',
            'password' => 'correct-horse-battery',
        ], [], []));
        $this->assertSame(302, $reg->status());

        $account = $site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertSame(200, $account->status());
        $this->assertStringContainsString('sample-notice', $account->body());

        $buy = $site->handle(new Request('POST', '/account/buy', [], ['_csrf' => $token], [], []));
        $this->assertSame(302, $buy->status());

        $account = $site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertSame(200, $account->status());
        $this->assertStringContainsString('演示订单完成', $account->body());
        $this->assertStringContainsString('/account/license.json?id=', $account->body());
        $this->assertStringContainsString('license-public.pem', $account->body());
        $this->assertStringContainsString('社区版', $account->body());
        preg_match('/授权编号 (lic_[a-f0-9]+).*授权令牌（仅显示一次）：([a-f0-9]+).*激活随机码（仅显示一次）：([a-f0-9]+)/u', $account->body(), $shown);
        $this->assertNotEmpty($shown, 'one-time token/nonce must appear after Fake buy');
        $licenseId = $shown[1];
        $secret = $shown[2];
        $nonce = $shown[3];

        $issuerOrders = $hub->issuerOrdersForAccount((int) $_SESSION['www_user']);
        $this->assertNotSame([], $issuerOrders);
        $this->assertSame('fake_completed', $issuerOrders[0]['status']);
        $this->assertSame($licenseId, $issuerOrders[0]['license_id']);

        $jsonDl = $site->handle(new Request('GET', '/account/license.json', ['id' => $licenseId], [], [], []));
        $this->assertSame(200, $jsonDl->status());
        $this->assertStringContainsString('application/json', (string) $jsonDl->header('Content-Type'));
        $this->assertSame('no-store', $jsonDl->header('Cache-Control'));
        $licenseJson = $jsonDl->body();
        $envelope = json_decode($licenseJson, true);
        $this->assertIsArray($envelope);
        $this->assertSame('Ed25519', $envelope['alg'] ?? null);
        $this->assertSame('sample-notice', $envelope['payload']['product_code'] ?? null);
        $this->assertContains('storefront_notice', $envelope['payload']['entitlements'] ?? []);
        $this->assertArrayHasKey('signature', $envelope);
        $this->assertStringNotContainsString($secret, $licenseJson);
        $this->assertStringNotContainsString($nonce, $licenseJson);
        $this->assertDoesNotLeakSecrets($licenseJson, $privPem);

        $pemDl = $site->handle(new Request('GET', '/account/license-public.pem', [], [], [], []));
        $this->assertSame(200, $pemDl->status());
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pemDl->body());
        $this->assertDoesNotLeakSecrets($pemDl->body(), $privPem);

        $gatePreview = new FileLicenseGate(
            $this->writeTempFile('lic.json', $licenseJson),
            $this->writeTempFile('pub.pem', $pemDl->body()),
            new FrozenClock(time()),
        );
        $this->assertTrue($gatePreview->allows(['storefront_notice']));

        // Authz: foreign id / other account / guest
        $foreign = $site->handle(new Request('GET', '/account/license.json', ['id' => 'lic_not_yours'], [], [], []));
        $this->assertSame(400, $foreign->status());

        $_SESSION = [];
        $token = $csrf->token();
        $otherReg = $site->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'other-e2e@example.invalid',
            'password' => 'correct-horse-battery',
        ], [], []));
        $this->assertSame(302, $otherReg->status());
        $denied = $site->handle(new Request('GET', '/account/license.json', ['id' => $licenseId], [], [], []));
        $this->assertSame(400, $denied->status());

        $_SESSION = [];
        $guest = $site->handle(new Request('GET', '/account/license.json', ['id' => $licenseId], [], [], []));
        $this->assertSame(401, $guest->status());

        // Security log must record download without secrets
        $audits = $pdoHub->query(
            "SELECT action, meta FROM security_log WHERE action = 'license_document_download' ORDER BY id DESC LIMIT 5"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($audits);
        foreach ($audits as $row) {
            $meta = (string) ($row['meta'] ?? '');
            $this->assertStringContainsString($licenseId, $meta);
            $this->assertStringNotContainsString($secret, $meta);
            $this->assertStringNotContainsString($nonce, $meta);
            $this->assertDoesNotLeakSecrets($meta, $privPem);
        }

        // --- Isolated Community ---
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        [$kernel, $pdoCommunity, $communityRoot, $auth, $csrfC] = $this->bootCommunity();
        $ctoken = $csrfC->token();

        // Core commerce before commercial module enabled
        $this->assertCommerceWorks($kernel, $auth, $csrfC, $communityRoot, 'shop-before@example.invalid', 'shopbefore1');

        // Authz: anonymous cannot import license
        $this->logoutAndResume($kernel, $auth, $csrfC, $communityRoot);
        $anonGet = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertSame(302, $anonGet->status());
        $anonPost = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $csrfC->token(),
            'license_json' => $licenseJson,
        ], [], []));
        $this->assertSame(302, $anonPost->status());
        $this->assertSame('/admin/login', $anonPost->header('Location'));

        $this->ensureSession($auth, $communityRoot);
        $this->assertTrue($auth->login('ops@example.invalid', 'correct-horse-battery', '127.0.0.1'));
        $ctoken = $csrfC->token();

        $modules = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertSame(200, $modules->status());
        $this->assertStringContainsString('trust', strtolower($modules->body()));

        // Import without trust key must fail and leave no license.json
        $noTrust = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => $licenseJson,
        ], [], []));
        $this->assertSame(422, $noTrust->status());
        $this->assertFileDoesNotExist($communityRoot . '/var/license.json');

        $trust = $kernel->handle(new Request('POST', '/admin/license/trust-key', [], [
            '_csrf' => $ctoken,
            'public_pem' => $pubPem,
        ], [], []));
        $this->assertSame(302, $trust->status());
        $this->assertFileExists($communityRoot . '/var/license-public.pem');
        $modules = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertStringContainsString(\IndependentMall\License\LicenseDocuments::fingerprint($pubPem), $modules->body());

        // Reject bundling an arbitrary PEM with license import
        $withPem = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => $licenseJson,
            'public_pem' => $pubPem,
        ], [], []));
        $this->assertSame(422, $withPem->status());
        $this->assertStringContainsString('许可证导入不能更换公钥', $withPem->body());
        $this->assertFileDoesNotExist($communityRoot . '/var/license.json');

        $import = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => $licenseJson,
        ], [], []));
        $this->assertSame(302, $import->status());
        $this->assertSame('/admin/modules', $import->header('Location'));

        $this->assertFileExists($communityRoot . '/var/license.json');
        $storedGate = FileLicenseGate::fromRoot($communityRoot);
        $this->assertInstanceOf(FileLicenseGate::class, $storedGate);
        $this->assertTrue($storedGate->allows(['storefront_notice']));
        $payload = $storedGate->payload();
        $this->assertSame('sample-notice', $payload['product_code'] ?? null);
        $this->assertDoesNotLeakSecrets((string) file_get_contents($communityRoot . '/var/license.json'), $privPem);
        $goodLicenseOnDisk = (string) file_get_contents($communityRoot . '/var/license.json');
        $goodPemOnDisk = (string) file_get_contents($communityRoot . '/var/license-public.pem');

        $audit = $pdoCommunity->query("SELECT action, entity_id, meta FROM audit_logs WHERE action = 'license.store' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($audit);
        $this->assertSame($licenseId, $audit['entity_id']);
        $this->assertStringNotContainsString($secret, (string) $audit['meta']);
        $this->assertDoesNotLeakSecrets((string) $audit['meta'], $privPem);

        $migrate = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/migrate', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(302, $migrate->status());
        $enable = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(302, $enable->status());

        $modules = $kernel->handle(new Request('GET', '/admin/modules', [], [], [], []));
        $this->assertStringContainsString('本地授权：是', $modules->body());
        $this->assertStringContainsString('sample-notice', $modules->body());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('示例商业公告', $home->body());
        $this->assertStringContainsString('正规商品', $home->body());

        $health = json_decode($kernel->handle(new Request('GET', '/healthz', [], [], [], []))->body(), true);
        $this->assertIsArray($health);
        $this->assertFalse($health['license_required']);
        $this->assertFalse($health['network_required']);
        $this->assertSame('ok', $health['status'] ?? null);
        $this->assertSame('offline_file', $health['license']['mode'] ?? null);
        $this->assertTrue($health['license']['valid'] ?? false);
        $this->assertSame('sample-notice', $health['license']['product_code'] ?? null);
        $this->assertContains('storefront_notice', $health['license']['entitlements'] ?? []);
        $this->assertArrayNotHasKey('token', $health);
        $this->assertArrayNotHasKey('nonce', $health);
        $this->assertStringNotContainsString($secret, json_encode($health) ?: '');
        $this->assertDoesNotLeakSecrets(json_encode($health) ?: '', $privPem);

        // Commerce still works with commercial module enabled
        $this->assertCommerceWorks($kernel, $auth, $csrfC, $communityRoot, 'shop-enabled@example.invalid', 'shopenable1');
        $this->logoutAndResume($kernel, $auth, $csrfC, $communityRoot);
        $this->assertTrue($auth->login('ops@example.invalid', 'correct-horse-battery', '127.0.0.1'));
        $ctoken = $csrfC->token();

        // --- Negatives: bad import must not destroy the good license or trust key ---
        $tampered = json_decode($licenseJson, true);
        $tampered['payload']['entitlements'][] = 'forged_entitlement';
        $bad = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => (string) json_encode($tampered),
        ], [], []));
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('原有授权未被修改', $bad->body());
        $this->assertSame($goodLicenseOnDisk, (string) file_get_contents($communityRoot . '/var/license.json'));
        $this->assertSame($goodPemOnDisk, (string) file_get_contents($communityRoot . '/var/license-public.pem'));
        $this->assertTrue(FileLicenseGate::fromRoot($communityRoot)->allows(['storefront_notice']));

        $other = Signer::generateKeypair();
        // Attempt to smuggle a different trust key via license import is rejected
        $smuggle = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => $licenseJson,
            'public_pem' => $other['public'],
        ], [], []));
        $this->assertSame(422, $smuggle->status());
        $this->assertSame($goodPemOnDisk, (string) file_get_contents($communityRoot . '/var/license-public.pem'));

        $foreignDoc = Signer::sign([
            'license_id' => 'lic_foreign',
            'instance_id' => 'shop-e2e',
            'entitlements' => ['storefront_notice'],
            'product_code' => 'sample-notice',
            'product_version' => '1.0.0',
            'customer_id' => 'cust_e2e',
            'issued_at' => gmdate('c'),
            'last_refresh_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + 86400 * 30),
            'grace_days' => 14,
            'revoked' => false,
        ], $other['private']);
        $wrongKey = $kernel->handle(new Request('POST', '/admin/license', [], [
            '_csrf' => $ctoken,
            'license_json' => (string) json_encode($foreignDoc),
        ], [], []));
        $this->assertSame(422, $wrongKey->status());
        $this->assertSame($goodLicenseOnDisk, (string) file_get_contents($communityRoot . '/var/license.json'));
        $this->assertSame($goodPemOnDisk, (string) file_get_contents($communityRoot . '/var/license-public.pem'));
        $this->assertTrue(FileLicenseGate::fromRoot($communityRoot)->allows(['storefront_notice']));

        // Rotating trust key without confirm fingerprint fails; good license + key remain
        $rotateDenied = $kernel->handle(new Request('POST', '/admin/license/trust-key', [], [
            '_csrf' => $ctoken,
            'public_pem' => $other['public'],
        ], [], []));
        $this->assertSame(422, $rotateDenied->status());
        $this->assertSame($goodPemOnDisk, (string) file_get_contents($communityRoot . '/var/license-public.pem'));

        $now = time();
        $expired = Signer::sign([
            'license_id' => 'lic_e2e_expired',
            'instance_id' => 'shop-e2e',
            'entitlements' => ['storefront_notice'],
            'product_code' => 'sample-notice',
            'product_version' => '1.0.0',
            'customer_id' => 'cust_e2e',
            'issued_at' => gmdate('c', $now - 86400 * 60),
            'last_refresh_at' => gmdate('c', $now - 86400 * 60),
            'expires_at' => gmdate('c', $now - 86400 * 40),
            'grace_days' => 7,
            'revoked' => false,
        ], $privPem);
        // Direct file write for expired/revoked/no-entitlement gate checks (import would succeed signature-wise for expired)
        file_put_contents($communityRoot . '/var/license.json', (string) json_encode($expired));
        $expiredGate = new FileLicenseGate(
            $communityRoot . '/var/license.json',
            $communityRoot . '/var/license-public.pem',
            new FrozenClock($now),
        );
        $this->assertFalse($expiredGate->allows(['storefront_notice']));
        $enableExpired = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(422, $enableExpired->status());

        $revoked = Signer::sign([
            'license_id' => 'lic_e2e_revoked',
            'instance_id' => 'shop-e2e',
            'entitlements' => ['storefront_notice'],
            'product_code' => 'sample-notice',
            'product_version' => '1.0.0',
            'customer_id' => 'cust_e2e',
            'issued_at' => gmdate('c', $now),
            'last_refresh_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + 86400 * 30),
            'grace_days' => 14,
            'revoked' => true,
        ], $privPem);
        file_put_contents($communityRoot . '/var/license.json', (string) json_encode($revoked));
        $revokedGate = FileLicenseGate::fromRoot($communityRoot);
        $this->assertInstanceOf(FileLicenseGate::class, $revokedGate);
        $this->assertFalse($revokedGate->allows(['storefront_notice']));
        $enableRevoked = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(422, $enableRevoked->status());

        $noEnt = Signer::sign([
            'license_id' => 'lic_e2e_noent',
            'instance_id' => 'shop-e2e',
            'entitlements' => [],
            'product_code' => 'sample-notice',
            'product_version' => '1.0.0',
            'customer_id' => 'cust_e2e',
            'issued_at' => gmdate('c', $now),
            'last_refresh_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + 86400 * 30),
            'grace_days' => 14,
            'revoked' => false,
        ], $privPem);
        file_put_contents($communityRoot . '/var/license.json', (string) json_encode($noEnt));
        $noEntGate = FileLicenseGate::fromRoot($communityRoot);
        $this->assertInstanceOf(FileLicenseGate::class, $noEntGate);
        $this->assertFalse($noEntGate->allows(['storefront_notice']));
        $enableNoEnt = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(422, $enableNoEnt->status());

        // Restore good license, enable, then disable — core commerce remains
        file_put_contents($communityRoot . '/var/license.json', $licenseJson);
        file_put_contents($communityRoot . '/var/license-public.pem', $pubPem);
        $kernel->handle(new Request('POST', '/admin/modules/sample-notice/enable', [], ['_csrf' => $ctoken], [], []));
        $disable = $kernel->handle(new Request('POST', '/admin/modules/sample-notice/disable', [], ['_csrf' => $ctoken], [], []));
        $this->assertSame(302, $disable->status());
        $offHome = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertStringNotContainsString('示例商业公告', $offHome->body());
        $this->assertStringContainsString('正规商品', $offHome->body());

        $this->assertCommerceWorks($kernel, $auth, $csrfC, $communityRoot, 'shop-disabled@example.invalid', 'shopdisable1');

        // Remove commercial package → graceful degrade
        $this->rmTree($communityRoot . '/modules/commercial');
        $this->ensureSession($auth, $communityRoot);
        $degHealth = json_decode($kernel->handle(new Request('GET', '/healthz', [], [], [], []))->body(), true);
        $this->assertSame('ok', $degHealth['status'] ?? null);
        $this->assertFalse($degHealth['license_required']);
        $this->assertFalse($degHealth['network_required']);
        $degHome = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $degHome->status());
        $this->assertStringContainsString('正规商品', $degHome->body());
        $this->assertCommerceWorks($kernel, $auth, $csrfC, $communityRoot, 'shop-degraded@example.invalid', 'shopdegrade1');
    }

    /**
     * @return array{0: Kernel, 1: \PDO, 2: string, 3: SessionAuth, 4: Csrf}
     */
    private function bootCommunity(): array
    {
        $tmp = $this->tempDir('community');
        mkdir($tmp . '/var', 0775, true);
        mkdir($tmp . '/modules/commercial/src', 0775, true);
        foreach (['module.php', 'autoload.php', 'README.md'] as $name) {
            copy($this->repoRoot . '/modules/commercial/' . $name, $tmp . '/modules/commercial/' . $name);
        }
        copy(
            $this->repoRoot . '/modules/commercial/src/SampleNoticeModule.php',
            $tmp . '/modules/commercial/src/SampleNoticeModule.php',
        );
        // Point DATABASE_URL at a file under tmp for installer-like isolation; use memory PDO injected into Kernel.
        file_put_contents($tmp . '/.env', "APP_KEY=community-e2e-key\nLICENSE_SERVER_URL=\nAPP_ENV=testing\n");
        file_put_contents($tmp . '/var/installed.json', '{"edition":"community","installed_at":"' . gmdate('c') . '"}');

        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $this->repoRoot . '/database/migrations'))->migrate();
        (new DemoSeeder($pdo))->seedIfEmpty();
        (new AdminProvisioner($pdo, new PasswordHasher()))->create('ops@example.invalid', 'ops', 'correct-horse-battery');

        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'k');
        $auth->start($tmp . '/var/sessions', 'ime2e', 3600, false);
        $csrf = new Csrf();
        $kernel = new Kernel(Config::load($tmp), $pdo, $auth, $csrf, new Renderer($this->repoRoot . '/templates'), new Router());

        return [$kernel, $pdo, $tmp, $auth, $csrf];
    }

    private function logoutAndResume(Kernel $kernel, SessionAuth $auth, Csrf $csrf, string $communityRoot): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $kernel->handle(new Request('POST', '/logout', [], ['_csrf' => $csrf->token()], [], []));
        }
        $this->ensureSession($auth, $communityRoot);
    }

    private function ensureSession(SessionAuth $auth, string $communityRoot): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $auth->start($communityRoot . '/var/sessions', 'ime2e', 3600, false);
    }

    private function assertCommerceWorks(
        Kernel $kernel,
        SessionAuth $auth,
        Csrf $csrf,
        string $communityRoot,
        string $email,
        string $username,
    ): void {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) {
            $kernel->handle(new Request('POST', '/logout', [], ['_csrf' => $csrf->token()], [], []));
        }
        $this->ensureSession($auth, $communityRoot);
        $token = $csrf->token();

        $reg = $kernel->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => $email,
            'username' => $username,
            'password' => 'correct-horse-1',
            'accept_terms' => '1',
        ], [], []));
        $this->assertSame(302, $reg->status(), $reg->body());

        $product = $kernel->handle(new Request('GET', '/p/ceramic-mug', [], [], [], []));
        $this->assertSame(200, $product->status());
        $this->assertStringContainsString('陶瓷杯', $product->body());
        $this->assertMatchesRegularExpression('/name="variant_id" value="(\d+)"/', $product->body());
        preg_match('/name="variant_id" value="(\d+)"/', $product->body(), $vm);

        $add = $kernel->handle(new Request('POST', '/cart/add', [], [
            '_csrf' => $token,
            'variant_id' => $vm[1],
            'qty' => '1',
        ], [], []));
        $this->assertSame(302, $add->status());

        $addr = $kernel->handle(new Request('POST', '/account/addresses', [], [
            '_csrf' => $token,
            'label' => 'Home',
            'recipient' => 'E2E Buyer',
            'phone' => '555-0100',
            'region' => 'ST',
            'line1' => '1 Test Street',
            'line2' => '',
            'postal_code' => '10000',
            'is_default' => '1',
        ], [], []));
        $this->assertSame(302, $addr->status(), $addr->body());

        $checkout = $kernel->handle(new Request('GET', '/checkout', [], [], [], []));
        $this->assertSame(200, $checkout->status());
        $this->assertMatchesRegularExpression('/name="address_id">[\s\S]*?<option value="(\d+)"/', $checkout->body());
        preg_match('/name="address_id">[\s\S]*?<option value="(\d+)"/', $checkout->body(), $am);

        $place = $kernel->handle(new Request('POST', '/checkout', [], [
            '_csrf' => $token,
            'address_id' => $am[1],
            'idempotency_key' => 'e2e-' . bin2hex(random_bytes(6)),
        ], [], []));
        $this->assertSame(302, $place->status(), $place->body());
        $this->assertMatchesRegularExpression('#/orders/\d+#', (string) $place->header('Location'));
        preg_match('#/orders/(\d+)#', (string) $place->header('Location'), $om);

        $orderPage = $kernel->handle(new Request('GET', '/orders/' . $om[1], [], [], [], []));
        $pay = $kernel->handle(new Request('POST', '/orders/' . $om[1] . '/pay-fake', [], [
            '_csrf' => $token,
            'outcome' => 'succeeded',
        ], [], []));
        $this->assertSame(302, $pay->status(), $orderPage->body());

        $paid = $kernel->handle(new Request('GET', '/orders/' . $om[1], [], [], [], []));
        $this->assertSame(200, $paid->status());
        $this->assertStringContainsString('已付款', $paid->body());
        $this->assertStringContainsString('等待商家发货', $paid->body());
    }

    private function assertDoesNotLeakSecrets(string $haystack, string $privatePem): void
    {
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $haystack);
        $this->assertStringNotContainsString(trim($privatePem), $haystack);
        $raw = preg_replace('/-----.*?-----|\s+/', '', $privatePem) ?? '';
        if (strlen($raw) > 40) {
            $this->assertStringNotContainsString(substr($raw, 0, 48), $haystack);
        }
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/im-e2e-' . $prefix . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->temps[] = $dir;

        return $dir;
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $dir = $this->tempDir('file');
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                @unlink($dir);
            }
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
