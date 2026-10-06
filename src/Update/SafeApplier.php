<?php

declare(strict_types=1);

namespace IndependentMall\Update;

final class SafeApplier
{
    /**
     * Copies the install directory, replaces NOTES.txt from a verified artifact, restores on failure.
     *
     * @param array<string, mixed> $manifest
     * @param callable():void|null $afterWrite
     */
    public function apply(
        string $installDir,
        string $artifactPath,
        array $manifest,
        string $publicPem,
        string $currentVersion,
        ?callable $afterWrite = null,
    ): string {
        PackageVerifier::assertSafeToApply($manifest, $artifactPath, $publicPem, $currentVersion);
        if (!is_dir($installDir)) {
            throw new \InvalidArgumentException('Install directory missing.');
        }
        $backup = rtrim($installDir, '/\\') . '.bak-' . bin2hex(random_bytes(3));
        $this->copyTree($installDir, $backup);
        try {
            $notes = $installDir . DIRECTORY_SEPARATOR . 'NOTES.txt';
            $ok = file_put_contents($notes, (string) file_get_contents($artifactPath));
            if ($ok === false) {
                throw new \RuntimeException('Could not write NOTES.txt.');
            }
            file_put_contents($installDir . DIRECTORY_SEPARATOR . 'VERSION', (string) $manifest['version']);
            if ($afterWrite !== null) {
                $afterWrite();
            }
            return $backup;
        } catch (\Throwable $e) {
            $this->copyTree($backup, $installDir);
            throw $e;
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException('Could not create backup directory.');
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($from) + 1);
            $dest = $to . DIRECTORY_SEPARATOR . $rel;
            if ($file->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0775, true);
                }
                continue;
            }
            copy($file->getPathname(), $dest);
        }
    }
}
