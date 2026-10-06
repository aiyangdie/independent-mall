<?php

declare(strict_types=1);

namespace IndependentMall\Tests;

use IndependentMall\License\CanonicalJson;
use IndependentMall\License\OpenSslEd25519;
use IndependentMall\LicenseServer\Signer;
use IndependentMall\Update\PackageVerifier;
use IndependentMall\Update\SafeApplier;
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

    public function testTamperHashMismatchAndMajorSkipAndPhpPayload(): void
    {
        $pair = Signer::generateKeypair();
        $root = sys_get_temp_dir() . '/im-upd-' . bin2hex(random_bytes(3));
        mkdir($root, 0775, true);
        $artifact = $root . '/pkg.txt';
        file_put_contents($artifact, "ok\n");
        $manifest = $this->manifest($artifact, $pair['private'], '0.1.1');
        file_put_contents($artifact, "tampered\n");
        try {
            PackageVerifier::assertSafeToApply($manifest, $artifact, $pair['public'], '0.1.0');
            $this->fail('tamper');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('SHA-256', $e->getMessage());
        }

        file_put_contents($artifact, "ok\n");
        $major = $this->manifest($artifact, $pair['private'], '1.0.0');
        try {
            PackageVerifier::assertSafeToApply($major, $artifact, $pair['public'], '0.1.0');
            $this->fail('major');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('major-version', $e->getMessage());
        }

        $phpFile = $root . '/evil.php';
        file_put_contents($phpFile, "<?php echo 1;\n");
        $evil = $this->manifest($phpFile, $pair['private'], '0.1.2');
        $this->expectException(\DomainException::class);
        PackageVerifier::assertSafeToApply($evil, $phpFile, $pair['public'], '0.1.0');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $artifact, string $private, string $version): array
    {
        $payload = [
            'artifact' => basename($artifact),
            'major' => (int) explode('.', $version)[0],
            'migration' => 'None.',
            'php' => '>=8.2',
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
