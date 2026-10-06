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
use IndependentMall\License\CanonicalJson;
use IndependentMall\License\OpenSslEd25519;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\Security\AuditLogger;
use IndependentMall\Update\PackageVerifier;
use IndependentMall\Update\SafeApplier;
use IndependentMall\View\Renderer;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    public function testUnsignedManifestIsRefused(): void
    {
        $pair = Signer::generateKeypair();
        $artifact = sys_get_temp_dir() . '/im-uns-' . bin2hex(random_bytes(3)) . '.txt';
        file_put_contents($artifact, "notes\n");
        $this->expectException(\DomainException::class);
        PackageVerifier::assertSafeToApply(
            ['signed' => false, 'version' => '0.1.0-dev', 'sha256' => hash_file('sha256', $artifact), 'signature' => ''],
            $artifact,
            $pair['public'],
            '0.1.0',
        );
    }

    public function testApplySucceedsAndFailedWriteRestoresBackup(): void
    {
        $pair = Signer::generateKeypair();
        $root = sys_get_temp_dir() . '/im-upd-' . bin2hex(random_bytes(3));
        mkdir($root . '/install', 0775, true);
        $install = $root . '/install';
        file_put_contents($install . '/NOTES.txt', 'old notes');
        file_put_contents($install . '/VERSION', '0.1.0');
        $artifact = $root . '/pkg.txt';
        file_put_contents($artifact, "new notes\n");
        $manifest = $this->manifest($artifact, $pair['private'], '0.1.1');
        $applier = new SafeApplier();
        $applier->apply($install, $artifact, $manifest, $pair['public'], '0.1.0');
        $this->assertSame("new notes\n", (string) file_get_contents($install . '/NOTES.txt'));
        $this->assertSame('0.1.1', trim((string) file_get_contents($install . '/VERSION')));

        file_put_contents($install . '/NOTES.txt', 'old notes');
        file_put_contents($install . '/VERSION', '0.1.0');
        try {
            $applier->apply($install, $artifact, $manifest, $pair['public'], '0.1.0', static function (): void {
                throw new \RuntimeException('simulated write failure');
            });
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated write failure', $e->getMessage());
        }
        $this->assertSame('old notes', (string) file_get_contents($install . '/NOTES.txt'));
        $this->assertSame('0.1.0', (string) file_get_contents($install . '/VERSION'));
    }

    public function testFailureModesRefuseAndNeverTouchInstallOrCommunityCore(): void
    {
        $pair = Signer::generateKeypair();
        $other = Signer::generateKeypair();
        $root = sys_get_temp_dir() . '/im-upd-fail-' . bin2hex(random_bytes(3));
        $install = $root . '/install';
        $community = $root . '/community-core';
        mkdir($install, 0775, true);
        mkdir($community . '/var', 0775, true);
        file_put_contents($install . '/NOTES.txt', 'keep-notes');
        file_put_contents($install . '/VERSION', '0.1.0');
        file_put_contents($install . '/shop.sqlite', 'db-bytes');
        file_put_contents($community . '/var/marker.txt', 'community-untouched');
        file_put_contents($community . '/.env', "APP_KEY=community-sentinel\nLICENSE_SERVER_URL=\n");

        $artifact = $root . '/pkg.txt';
        file_put_contents($artifact, "ok-payload\n");
        $good = $this->manifest($artifact, $pair['private'], '0.1.1');
        $applier = new SafeApplier();

        $cases = [];

        $unsigned = $good;
        $unsigned['signed'] = false;
        $unsigned['signature'] = '';
        $cases['unsigned'] = [$unsigned, $artifact, $pair['public'], 'Unsigned'];

        $badSig = $good;
        $badSig['signature'] = base64_encode(random_bytes(64));
        $cases['bad signature'] = [$badSig, $artifact, $pair['public'], 'signature'];

        $tamperedPayload = $good;
        $tamperedPayload['version'] = '0.1.9';
        $cases['tampered manifest'] = [$tamperedPayload, $artifact, $pair['public'], 'signature'];

        $cases['wrong public key'] = [$good, $artifact, $other['public'], 'signature'];

        file_put_contents($artifact, "ok-payload\n");
        $mismatchArtifact = $root . '/pkg-mismatch.txt';
        file_put_contents($mismatchArtifact, "ok-payload\n");
        $hashMismatch = $this->manifest($mismatchArtifact, $pair['private'], '0.1.1');
        file_put_contents($mismatchArtifact, "tampered-bytes\n");
        $cases['hash mismatch'] = [$hashMismatch, $mismatchArtifact, $pair['public'], 'SHA-256'];

        file_put_contents($artifact, "ok-payload\n");
        $major = $this->manifest($artifact, $pair['private'], '1.0.0');
        $cases['major incompatible'] = [$major, $artifact, $pair['public'], 'major-version'];

        file_put_contents($artifact, "ok-payload\n");
        $phpNeed = $this->manifest($artifact, $pair['private'], '0.1.1', '>=99.0');
        $cases['php incompatible'] = [$phpNeed, $artifact, $pair['public'], 'PHP is too old'];

        $phpFile = $root . '/evil.php';
        file_put_contents($phpFile, "<?php echo 1;\n");
        $evil = $this->manifest($phpFile, $pair['private'], '0.1.2');
        $cases['php payload'] = [$evil, $phpFile, $pair['public'], 'executable'];

        foreach ($cases as $name => [$manifest, $path, $pub, $needle]) {
            file_put_contents($install . '/NOTES.txt', 'keep-notes');
            file_put_contents($install . '/VERSION', '0.1.0');
            file_put_contents($install . '/shop.sqlite', 'db-bytes');
            file_put_contents($community . '/var/marker.txt', 'community-untouched');
            $beforeCommunityEnv = (string) file_get_contents($community . '/.env');
            try {
                $applier->apply($install, $path, $manifest, $pub, '0.1.0');
                $this->fail('expected refusal for ' . $name);
            } catch (\DomainException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $name);
            }
            $this->assertSame('keep-notes', (string) file_get_contents($install . '/NOTES.txt'), $name);
            $this->assertSame('0.1.0', (string) file_get_contents($install . '/VERSION'), $name);
            $this->assertSame('db-bytes', (string) file_get_contents($install . '/shop.sqlite'), $name);
            $this->assertSame('community-untouched', (string) file_get_contents($community . '/var/marker.txt'), $name);
            $this->assertSame($beforeCommunityEnv, (string) file_get_contents($community . '/.env'), $name);
            $this->assertSame([], glob($root . '/install.bak-*') ?: [], $name . ' must not leave backup dirs after verify failure');
        }

        // Community Kernel still serves after update refusals (same machine, separate tree).
        $repo = dirname(__DIR__);
        $pdo = ConnectionFactory::make('sqlite:///:memory:');
        (new Migrator($pdo, $repo . '/database/migrations'))->migrate();
        file_put_contents($community . '/var/installed.json', '{"edition":"community"}');
        $auth = new SessionAuth($pdo, new PasswordHasher(), new RateLimiter($pdo), new AuditLogger($pdo), 'k');
        $kernel = new Kernel(Config::load($community), $pdo, $auth, new Csrf(), new Renderer($repo . '/templates'), new Router());
        $home = $kernel->handle(new Request('GET', '/', [], [], [], []));
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('lawful trade', $home->body());
        $health = json_decode($kernel->handle(new Request('GET', '/healthz', [], [], [], []))->body(), true);
        $this->assertFalse($health['license_required']);
        $this->assertFalse($health['network_required']);
        $this->assertSame('community-untouched', (string) file_get_contents($community . '/var/marker.txt'));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $artifact, string $private, string $version, string $php = '>=8.2'): array
    {
        $payload = [
            'artifact' => basename($artifact),
            'major' => (int) explode('.', $version)[0],
            'migration' => 'None.',
            'php' => $php,
            'rollback' => 'restore backup',
            'sha256' => (string) hash_file('sha256', $artifact),
            'version' => $version,
        ];
        return $payload + [
            'signature' => base64_encode(OpenSslEd25519::sign(CanonicalJson::encode($payload), $private)),
            'signed' => true,
        ];
    }
}
