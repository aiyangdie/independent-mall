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
            $this->assertStringContainsString('律师', $res->body());
            $this->assertStringContainsString('跳到主要内容', $res->body());
        }
        $pricing = $this->site->handle(new Request('GET', '/pricing', [], [], [], []));
        $this->assertStringContainsString('当前仅支持演示支付', $pricing->body());
        $this->assertStringContainsString('尚未经过律师审核', $pricing->body());
        $this->assertStringContainsString('callout-fake', $pricing->body());
        $this->assertStringContainsString('callout-legal', $pricing->body());
        $this->assertStringContainsString('/legal/disclaimer', $pricing->body());
        $this->assertStringContainsString('演示操作流程', $pricing->body());
        $this->assertStringContainsString('演示购买', $pricing->body());
        $this->assertStringContainsString('演示续费完成', $pricing->body());
        $this->assertStringContainsString('/admin/license/trust-key', $pricing->body());
        $this->assertStringContainsString('/admin/license', $pricing->body());
        $this->assertStringContainsString('config/license-public.pem', $pricing->body());
        $this->assertStringContainsString('/docs', $pricing->body());
        $changelog = $this->site->handle(new Request('GET', '/changelog', [], [], [], []));
        $this->assertStringContainsString('0.1.1-dev', $changelog->body());
        $this->assertStringContainsString('演示支付', $changelog->body());
        $this->assertStringContainsString('不是正式生产版', $changelog->body());
        $docs = $this->site->handle(new Request('GET', '/docs', [], [], [], []));
        $this->assertStringContainsString('docs/deploy.md', $docs->body());
        $this->assertStringContainsString('docs/license-server-data.md', $docs->body());
        $this->assertStringContainsString('不会把更新包自动安装', $docs->body());
        $this->assertStringContainsString('docs/ops.md', $docs->body());
        $this->assertStringContainsString('价格演示流程', $docs->body());
        $this->assertStringContainsString('续费', $docs->body());
        $editions = $this->site->handle(new Request('GET', '/editions', [], [], [], []));
        $this->assertStringContainsString('table-wrap', $editions->body());
        $refunds = $this->site->handle(new Request('GET', '/legal/refunds', [], [], [], []));
        $this->assertStringContainsString('消费者保护法的强制规定', $refunds->body());
        $ban = $this->site->handle(new Request('GET', '/legal/prohibited', [], [], [], []));
        $this->assertStringContainsString('账号交易', $ban->body());
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
        $this->assertStringContainsString('验签通过，尚未安装', $page->body());
        $this->assertStringContainsString('已拒绝自动安装', $page->body());
        $this->assertStringContainsString('是否已安装：否', $page->body());
        $this->assertSame($before, (string) file_get_contents($artifact));
        $this->assertSame($mtime, filemtime($artifact));
        $account = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('检查更新', $account->body());
    }

    public function testInspectUpdateFailuresDoNotMutateArtifactsOrCommunity(): void
    {
        $repo = dirname(__DIR__);
        $filesDir = sys_get_temp_dir() . '/im-www-upd-' . bin2hex(random_bytes(3));
        mkdir($filesDir, 0775, true);
        $unsignedSrc = $repo . '/apps/www/data/files/community-0.1.0-dev.txt';
        $signedSrc = $repo . '/apps/www/data/files/community-0.1.1.txt';
        copy($unsignedSrc, $filesDir . '/' . Hub::FILES['community-notes']);
        copy($signedSrc, $filesDir . '/' . Hub::FILES['community-0.1.1']);
        $community = sys_get_temp_dir() . '/im-www-community-' . bin2hex(random_bytes(3));
        mkdir($community . '/var', 0775, true);
        file_put_contents($community . '/var/marker.txt', 'community-core-safe');
        file_put_contents($community . '/.env', "APP_KEY=sentinel\n");

        $pair = Signer::generateKeypair();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $hub = new Hub(
            $pdo,
            new PasswordHasher(),
            new Store($pdo),
            'hmac-inspect-fail',
            $filesDir,
            $pair['private'],
            $pair['public'],
        );

        $signedPath = $filesDir . '/' . Hub::FILES['community-0.1.1'];
        $unsignedPath = $filesDir . '/' . Hub::FILES['community-notes'];
        $beforeSigned = (string) file_get_contents($signedPath);
        $beforeUnsigned = (string) file_get_contents($unsignedPath);

        // Happy inspect: verify only
        $ok = $hub->inspectUpdates(null);
        $this->assertSame('refused_auto_install', $ok['unsigned']);
        $this->assertSame('verified_not_applied', $ok['signed']);
        $this->assertFalse($ok['applied']);
        $this->assertSame($beforeSigned, (string) file_get_contents($signedPath));
        $this->assertSame($beforeUnsigned, (string) file_get_contents($unsignedPath));

        // Frozen metadata + tampered artifact => SHA-256 mismatch; SafeApplier must not write
        $frozen = $hub->updates()['signed_release'];
        $install = sys_get_temp_dir() . '/im-www-install-' . bin2hex(random_bytes(3));
        mkdir($install, 0775, true);
        file_put_contents($install . '/NOTES.txt', 'old-notes');
        file_put_contents($install . '/VERSION', '0.1.0');
        file_put_contents($signedPath, $beforeSigned . "\nTAMPER\n");
        try {
            (new SafeApplier())->apply($install, $signedPath, $frozen, $pair['public'], '0.1.0');
            $this->fail('tampered artifact must refuse');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }
        $this->assertSame('old-notes', (string) file_get_contents($install . '/NOTES.txt'));
        $this->assertSame('0.1.0', (string) file_get_contents($install . '/VERSION'));
        $this->assertSame([], glob($install . '.bak-*') ?: []);
        file_put_contents($signedPath, $beforeSigned);

        // Wrong trust key on inspect: failed signature, no apply, artifacts unchanged
        $other = Signer::generateKeypair();
        $wrongKeyHub = new Hub(
            $pdo,
            new PasswordHasher(),
            new Store($pdo),
            'hmac-inspect-fail',
            $filesDir,
            $pair['private'],
            $other['public'],
        );
        $badSig = $wrongKeyHub->inspectUpdates(null);
        $this->assertSame('refused_auto_install', $badSig['unsigned']);
        $this->assertStringStartsWith('failed:', $badSig['signed']);
        $this->assertStringContainsString('signature', strtolower($badSig['signed']));
        $this->assertFalse($badSig['applied']);
        $this->assertSame($beforeSigned, (string) file_get_contents($signedPath));
        $this->assertSame($beforeUnsigned, (string) file_get_contents($unsignedPath));
        $this->assertSame('community-core-safe', (string) file_get_contents($community . '/var/marker.txt'));
        $this->assertSame("APP_KEY=sentinel\n", (string) file_get_contents($community . '/.env'));

        // Unsigned channel must never be auto-applied
        $updates = $hub->updates();
        $this->assertFalse($updates['latest']['signed']);
        try {
            PackageVerifier::assertSafeToApply(
                $updates['latest'],
                $unsignedPath,
                $pair['public'],
                '0.1.0',
            );
            $this->fail('unsigned must refuse');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Unsigned', $e->getMessage());
        }
        $this->assertSame($beforeUnsigned, (string) file_get_contents($unsignedPath));
        $this->assertSame('community-core-safe', (string) file_get_contents($community . '/var/marker.txt'));
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
        $this->assertStringContainsString('演示订单完成', $account->body());
        $this->assertStringContainsString('sample-notice@1.0.0', $account->body());
        $this->assertStringContainsString('产品 <code>sample-notice@1.0.0</code>', $account->body());
        $this->assertStringContainsString('授权中心商业订单', $account->body());
        $this->assertStringContainsString('官网演示订单', $account->body());
        $this->assertMatchesRegularExpression('/客户 www_u\d+/', $account->body());
        $this->assertMatchesRegularExpression('/授权订单 ord_[a-f0-9]+/', $account->body());
        $this->assertMatchesRegularExpression('/<code>ord_[a-f0-9]+<\/code>/', $account->body());
        $this->assertMatchesRegularExpression('/lic_[a-f0-9]+/', $account->body());
        preg_match('/授权编号 (lic_[a-f0-9]+).*授权令牌（仅显示一次）：([a-f0-9]+).*激活随机码（仅显示一次）：([a-f0-9]+)/u', $account->body(), $m);
        $this->assertNotEmpty($m);
        $licenseId = $m[1];
        $secret = $m[2];
        $nonce = $m[3];
        $docsBuy = $this->hub->licenses((int) $_SESSION['www_user']);
        $this->assertSame('sample-notice', $docsBuy[0]['product_code'] ?? null);
        $this->assertSame('1.0.0', $docsBuy[0]['product_version'] ?? null);
        $this->assertMatchesRegularExpression('/^www_u\d+$/', (string) ($docsBuy[0]['customer_id'] ?? ''));
        $envBuy = json_decode((string) $docsBuy[0]['document'], true);
        $this->assertSame('sample-notice', $envBuy['payload']['product_code'] ?? null);
        $this->assertSame($docsBuy[0]['customer_id'], $envBuy['payload']['customer_id'] ?? null);
        $commercial = $this->hub->issuerOrdersForAccount((int) $_SESSION['www_user']);
        $this->assertNotSame([], $commercial);
        $this->assertSame($licenseId, $commercial[0]['license_id']);
        $this->assertSame('fake_completed', $commercial[0]['status']);
        $this->assertStringContainsString((string) $commercial[0]['id'], $account->body());
        $this->assertStringContainsString('/account/license.json?id=', $account->body());
        $this->assertStringContainsString('/account/license-public.pem', $account->body());
        $licDl = $this->site->handle(new Request('GET', '/account/license.json', ['id' => $licenseId], [], [], []));
        $this->assertSame(200, $licDl->status());
        $this->assertStringContainsString('application/json', (string) $licDl->header('Content-Type'));
        $this->assertSame('no-store', $licDl->header('Cache-Control'));
        $envelope = json_decode($licDl->body(), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('payload', $envelope);
        $this->assertArrayHasKey('signature', $envelope);
        $this->assertSame($licenseId, $envelope['payload']['license_id'] ?? null);
        $this->assertStringNotContainsString($secret, $licDl->body());
        $pemDl = $this->site->handle(new Request('GET', '/account/license-public.pem', [], [], [], []));
        $this->assertSame(200, $pemDl->status());
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pemDl->body());
        $docPath = sys_get_temp_dir() . '/im-www-lic-' . bin2hex(random_bytes(3)) . '.json';
        $pemPath = sys_get_temp_dir() . '/im-www-pem-' . bin2hex(random_bytes(3)) . '.pem';
        file_put_contents($docPath, $licDl->body());
        file_put_contents($pemPath, $pemDl->body());
        $verifyGate = new FileLicenseGate($docPath, $pemPath, new FrozenClock(time()));
        $this->assertTrue($verifyGate->allows(['storefront_notice']));
        $this->assertStringContainsString('下载签名授权文件', $this->site->handle(new Request('GET', '/account', [], [], [], []))->body());
        $foreign = $this->site->handle(new Request('GET', '/account/license.json', ['id' => 'lic_not_yours'], [], [], []));
        $this->assertSame(400, $foreign->status());

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
        $this->assertStringContainsString('已绑定', $boundPage->body());
        $this->assertStringContainsString('shop-demo-1', $boundPage->body());

        $this->site->handle(new Request('POST', '/account/unbind', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
        ], [], []));
        $afterUnbind = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('尚未绑定实例', $afterUnbind->body());
        preg_match('/下次激活随机码（仅显示一次）：([a-f0-9]+)/u', $afterUnbind->body(), $n2);
        $this->assertNotEmpty($n2);
        $this->site->handle(new Request('POST', '/account/activate', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'nonce' => $n2[1],
            'instance_id' => 'shop-demo-2',
        ], [], []));
        $boundAgain = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('状态 <strong>已绑定</strong>', $boundAgain->body());
        preg_match('/到期时间 <code>([^<]+)<\/code>/', $boundAgain->body(), $expBefore);
        $this->assertNotEmpty($expBefore);
        $beforeTs = strtotime($expBefore[1]);
        $this->assertNotFalse($beforeTs);

        $renew = $this->site->handle(new Request('POST', '/account/renew', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'days' => '30',
        ], [], []));
        $this->assertSame(302, $renew->status());
        $renewedPage = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('演示续费已记录', $renewedPage->body());
        $this->assertStringContainsString('commercial-sample-notice-renew', $renewedPage->body());
        $this->assertStringContainsString('演示续费', $renewedPage->body());
        $this->assertStringContainsString('演示续费完成', $renewedPage->body());
        $this->assertMatchesRegularExpression('/授权订单 ord_[a-f0-9]+/u', $renewedPage->body());
        preg_match('/到期时间 <code>([^<]+)<\/code>/', $renewedPage->body(), $expAfter);
        $this->assertNotEmpty($expAfter);
        $afterTs = strtotime($expAfter[1]);
        $this->assertNotFalse($afterTs);
        $this->assertSame($beforeTs + 30 * 86400, $afterTs);
        $this->assertStringContainsString('shop-demo-2', $renewedPage->body());
        $issuerAfterRenew = $this->hub->issuerOrdersForAccount((int) $_SESSION['www_user']);
        $this->assertGreaterThanOrEqual(2, count($issuerAfterRenew));
        $statuses = array_column($issuerAfterRenew, 'status');
        $this->assertContains('fake_completed', $statuses);
        $this->assertContains('fake_renewed', $statuses);

        $this->site->handle(new Request('POST', '/account/revoke', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
        ], [], []));
        $blocked = $this->site->handle(new Request('POST', '/account/renew', [], [
            '_csrf' => $token,
            'license_id' => $licenseId,
            'token' => $secret,
            'days' => '30',
        ], [], []));
        $this->assertSame(400, $blocked->status());
        $this->assertStringContainsString('授权已吊销', $blocked->body());
        $revokedPage = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('状态 <strong>已吊销</strong>', $revokedPage->body());
        $this->assertStringContainsString('商业模块已停用', $revokedPage->body());
        $docs = $this->hub->licenses((int) $_SESSION['www_user']);
        $this->assertTrue(!empty($docs[0]['revoked']));

        $this->site->handle(new Request('POST', '/account/ticket', [], [
            '_csrf' => $token,
            'title' => 'Need invoice',
            'body' => 'Draft only.',
        ], [], []));
        $again = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('Need invoice', $again->body());
        $this->assertStringContainsString('状态 <code>待处理</code>', $again->body());
        preg_match('/name="ticket_id" value="(\d+)"/', $again->body(), $tid);
        $this->assertNotEmpty($tid);
        $this->site->handle(new Request('POST', '/account/ticket/answer', [], [
            '_csrf' => $token,
            'ticket_id' => $tid[1],
            'reply' => 'Fake staff: draft invoice noted.',
        ], [], []));
        $answered = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('状态 <code>已回复</code>', $answered->body());
        $this->assertStringContainsString('Fake staff: draft invoice noted.', $answered->body());
        $this->assertStringContainsString('回复工单', $answered->body());
        $this->site->handle(new Request('POST', '/account/ticket/close', [], [
            '_csrf' => $token,
            'ticket_id' => $tid[1],
        ], [], []));
        $closed = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('状态 <code>已关闭</code>', $closed->body());
        $this->assertStringContainsString('关闭工单', $closed->body());
        $reclose = $this->site->handle(new Request('POST', '/account/ticket/close', [], [
            '_csrf' => $token,
            'ticket_id' => $tid[1],
        ], [], []));
        $this->assertSame(400, $reclose->status());
        $this->assertStringContainsString('工单已关闭', $reclose->body());

        preg_match('/download\?token=([a-z0-9-]+\.[0-9]+\.[a-f0-9]+\.[a-f0-9]+)/', $closed->body(), $dl);
        $this->assertNotEmpty($dl);
        $file = $this->site->handle(new Request('GET', '/account/download', ['token' => $dl[1]], [], [], []));
        $this->assertSame(200, $file->status());
        $this->assertStringContainsString('must not be executed', $file->body());
        $this->assertSame('no-store', $file->header('Cache-Control'));
        $reuse = $this->site->handle(new Request('GET', '/account/download', ['token' => $dl[1]], [], [], []));
        $this->assertSame(400, $reuse->status());
        $this->assertStringContainsString('下载令牌已被拒绝', $reuse->body());

        $freshAccount = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        preg_match('/download\?token=([a-z0-9-]+\.[0-9]+\.[a-f0-9]+\.[a-f0-9]+)/', $freshAccount->body(), $dl2);
        $this->assertNotEmpty($dl2);
        $parts = explode('.', $dl2[1]);
        $this->assertCount(4, $parts);
        $this->site->handle(new Request('POST', '/account/download/revoke', [], [
            '_csrf' => $token,
            'jti' => $parts[2],
        ], [], []));
        $afterRevoke = $this->site->handle(new Request('GET', '/account/download', ['token' => $dl2[1]], [], [], []));
        $this->assertSame(400, $afterRevoke->status());
        $this->assertStringContainsString('撤销下载链接', $this->site->handle(new Request('GET', '/account', [], [], [], []))->body());

        $bad = $this->site->handle(new Request('GET', '/account/download', ['token' => '../sample-notice'], [], [], []));
        $this->assertSame(400, $bad->status());
        $audited = $this->site->handle(new Request('GET', '/account', [], [], [], []));
        $this->assertStringContainsString('下载审计', $audited->body());
        $this->assertStringContainsString('已允许', $audited->body());
        $this->assertStringContainsString('已拒绝', $audited->body());
        $this->assertStringContainsString('单次', $audited->body());
        $this->assertStringNotContainsString($dl[1], $audited->body());
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
