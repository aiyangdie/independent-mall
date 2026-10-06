<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\Auth\PasswordHasher;
use IndependentMall\Http\Csrf;
use IndependentMall\Http\Request;
use IndependentMall\License\FileLicenseGate;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\LicenseServer\Store;
use IndependentMall\OfficialSite\Hub;
use IndependentMall\OfficialSite\Site;
use IndependentMall\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class OfficialSiteTest extends TestCase
{
    private Site $site;
    private Hub $hub;
    private string $publicPem;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pair = Signer::generateKeypair();
        $this->publicPem = $pair['public'];
        $root = dirname(__DIR__);
        $this->hub = new Hub(
            $pdo,
            new PasswordHasher(),
            new Store($pdo),
            'hmac-test-key-not-for-production',
            $root . '/apps/www/data/files',
            $pair['private'],
            $pair['public'],
        );
        $this->site = Site::forTests($this->hub, new Csrf(), $root . '/apps/www/templates');
    }

    public function testMarketingPagesAndUnsignedUpdates(): void
    {
        foreach (['/', '/editions', '/pricing', '/docs', '/changelog', '/contact', '/legal/terms'] as $path) {
            $res = $this->site->handle(new Request('GET', $path, [], [], [], []));
            $this->assertSame(200, $res->status(), $path);
            $this->assertStringNotContainsString('彩虹', $res->body());
            $this->assertStringNotContainsString('代刷', $res->body());
        }
        $updates = json_decode($this->site->handle(new Request('GET', '/updates.json', [], [], [], []))->body(), true);
        $this->assertFalse($updates['latest']['signed']);
        $this->assertSame(64, strlen((string) $updates['latest']['sha256']));
        $notes = dirname(__DIR__) . '/apps/www/data/files/community-0.1.0-dev.txt';
        $this->assertSame(hash_file('sha256', $notes), $updates['latest']['sha256']);
    }

    public function testFakeBuyIssueActivateDownloadAndTraversalDenied(): void
    {
        $token = (new Csrf())->token();
        $reg = $this->site->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'buyer@example.invalid',
            'password' => 'correct-horse-battery',
        ], [], []));
        $this->assertSame(302, $reg->status());

        $buy = $this->site->handle(new Request('POST', '/account/buy', [], ['_csrf' => $token], [], []));
        $this->assertSame(302, $buy->status());
        $account = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertSame(200, $account->status());
        $this->assertStringContainsString('fake_completed', $account->body());
        $this->assertMatchesRegularExpression('/lic_[a-f0-9]+/', $account->body());
        preg_match('/License (lic_[a-f0-9]+) token \(shown once\): ([a-f0-9]+)/', $account->body(), $m);
        $this->assertNotEmpty($m);
        $licenseId = $m[1];
        $secret = $m[2];

        $this->site->handle(new Request('POST', '/account/activate', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'instance_id' => 'shop-demo-1',
        ], [], []));
        $docs = $this->hub->licenses((int) $_SESSION['www_user']);
        $env = json_decode((string) $docs[0]['document'], true);
        $dir = sys_get_temp_dir() . '/im-www-' . bin2hex(random_bytes(3));
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/license.json', json_encode($env));
        file_put_contents($dir . '/pub.pem', $this->publicPem);
        $gate = new FileLicenseGate($dir . '/license.json', $dir . '/pub.pem', new FrozenClock(time()));
        $this->assertTrue($gate->allows(['storefront_notice']));

        $this->site->handle(new Request('POST', '/account/unbind', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
        ], [], []));
        $this->site->handle(new Request('POST', '/account/activate', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'instance_id' => 'shop-demo-2',
        ], [], []));

        $this->site->handle(new Request('POST', '/account/ticket', [], [
            '_csrf' => $token,
            'title' => 'Need invoice',
            'body' => 'Draft only.',
        ], [], []));
        $again = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('Need invoice', $again->body());

        preg_match('/download\?token=([a-z0-9-]+\.[0-9]+\.[a-f0-9]+)/', $again->body(), $dl);
        $this->assertNotEmpty($dl);
        $file = $this->site->handle(new Request('GET', '/account/download', ['token' => $dl[1]], [], [], []));
        $this->assertSame(200, $file->status());
        $this->assertStringContainsString('must not be executed', $file->body());
        $this->assertSame('no-store', $file->header('Cache-Control'));

        $bad = $this->site->handle(new Request('GET', '/account/download', ['token' => '../sample-notice'], [], [], []));
        $this->assertSame(400, $bad->status());
    }

    public function testCommunitySrcDoesNotImportOfficialSite(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('IndependentMall\\OfficialSite', $code, $file->getPathname());
            $this->assertStringNotContainsString('apps/www', $code, $file->getPathname());
        }
    }
}
