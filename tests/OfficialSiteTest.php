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
use IndependentMall\Update\PackageVerifier;
use IndependentMall\Update\SafeApplier;
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
        foreach (['/', '/editions', '/pricing', '/docs', '/changelog', '/contact', '/legal/terms', '/legal/refunds', '/legal/prohibited', '/legal/disclaimer'] as $path) {
            $res = $this->site->handle(new Request('GET', $path, [], [], [], []));
            $this->assertSame(200, $res->status(), $path);
            $this->assertStringNotContainsString('彩虹', $res->body());
            $this->assertStringNotContainsString('代刷', $res->body());
            $this->assertStringContainsString('Counsel has not reviewed', $res->body());
            $this->assertStringContainsString('Skip to content', $res->body());
        }
        $editions = $this->site->handle(new Request('GET', '/editions', [], [], [], []));
        $this->assertStringContainsString('table-wrap', $editions->body());
        $refunds = $this->site->handle(new Request('GET', '/legal/refunds', [], [], [], []));
        $this->assertStringContainsString('mandatory consumer law', $refunds->body());
        $ban = $this->site->handle(new Request('GET', '/legal/prohibited', [], [], [], []));
        $this->assertStringContainsString('account trading', $ban->body());
        $updates = json_decode($this->site->handle(new Request('GET', '/updates.json', [], [], [], []))->body(), true);
        $this->assertFalse($updates['latest']['signed']);
        $this->assertTrue($updates['signed_release']['signed']);
        $this->assertNotSame('', $updates['signed_release']['signature']);
        $this->assertSame(64, strlen((string) $updates['latest']['sha256']));
        $notes = dirname(__DIR__) . '/apps/www/data/files/community-0.1.0-dev.txt';
        $this->assertSame(hash_file('sha256', $notes), $updates['latest']['sha256']);
        $this->expectException(\DomainException::class);
        PackageVerifier::assertSafeToApply($updates['latest'], $notes, $this->publicPem, '0.1.0');
    }

    public function testSignedReleaseFromOfficialSiteApplies(): void
    {
        $updates = json_decode($this->site->handle(new Request('GET', '/updates.json', [], [], [], []))->body(), true);
        $artifact = dirname(__DIR__) . '/apps/www/data/files/community-0.1.1.txt';
        $install = sys_get_temp_dir() . '/im-site-upd-' . bin2hex(random_bytes(3));
        mkdir($install, 0775, true);
        file_put_contents($install . '/NOTES.txt', 'old');
        file_put_contents($install . '/VERSION', '0.1.0');
        (new SafeApplier())->apply($install, $artifact, $updates['signed_release'], $updates['update_public_key'], '0.1.0');
        $this->assertStringContainsString('0.1.1 signed notes', (string) file_get_contents($install . '/NOTES.txt'));
    }

    public function testInspectUpdatesDoesNotApply(): void
    {
        $guest = $this->site->handle(new Request('GET', '/account/updates', [], [], [], []));
        $this->assertSame(302, $guest->status());
        $token = (new Csrf())->token();
        $this->site->handle(new Request('POST', '/register', [], [
            '_csrf' => $token,
            'email' => 'ops@example.invalid',
            'password' => 'correct-horse-battery',
        ], [], []));
        $artifact = dirname(__DIR__) . '/apps/www/data/files/community-0.1.1.txt';
        $before = (string) file_get_contents($artifact);
        $mtime = filemtime($artifact);
        $page = $this->site->handle(new Request('GET', '/account/updates', [], [], [], []));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('verified_not_applied', $page->body());
        $this->assertStringContainsString('refused_auto_install', $page->body());
        $this->assertStringContainsString('Applied: no', $page->body());
        $this->assertSame($before, (string) file_get_contents($artifact));
        $this->assertSame($mtime, filemtime($artifact));
        $account = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('inspect_updates', $account->body());
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
        preg_match('/License (lic_[a-f0-9]+) token \(shown once\): ([a-f0-9]+) nonce \(shown once\): ([a-f0-9]+)/', $account->body(), $m);
        $this->assertNotEmpty($m);
        $licenseId = $m[1];
        $secret = $m[2];
        $nonce = $m[3];

        $this->site->handle(new Request('POST', '/account/activate', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'nonce' => $nonce,
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
        $boundPage = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('bound to', $boundPage->body());
        $this->assertStringContainsString('shop-demo-1', $boundPage->body());

        $this->site->handle(new Request('POST', '/account/unbind', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
        ], [], []));
        $afterUnbind = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('unbound', $afterUnbind->body());
        preg_match('/Next activation nonce \(shown once\): ([a-f0-9]+)/', $afterUnbind->body(), $n2);
        $this->assertNotEmpty($n2);
        $this->site->handle(new Request('POST', '/account/activate', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'nonce' => $n2[1],
            'instance_id' => 'shop-demo-2',
        ], [], []));
        $boundAgain = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('status <strong>bound</strong>', $boundAgain->body());

        $this->site->handle(new Request('POST', '/account/revoke', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
        ], [], []));
        $revokedPage = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('status <strong>revoked</strong>', $revokedPage->body());
        $this->assertStringContainsString('commercial modules off', $revokedPage->body());
        $docs = $this->hub->licenses((int) $_SESSION['www_user']);
        $this->assertTrue(!empty($docs[0]['revoked']));

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
        $audited = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('Download audit', $audited->body());
        $this->assertStringContainsString('allowed', $audited->body());
        $this->assertStringContainsString('denied', $audited->body());
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
