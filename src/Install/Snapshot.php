<?php

declare(strict_types=1);

namespace IndependentMall\Install;

final class Snapshot
{
    public function __construct(private string $root)
    {
    }

    public function backupsDir(): string
    {
        return $this->root . '/var/backups';
    }

    public function create(): string
    {
        $dir = $this->backupsDir() . '/snap-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create backup directory.');
        }
        $copied = [];
        foreach (['.env', 'var/installed.json'] as $rel) {
            $src = $this->root . '/' . $rel;
            if (is_file($src)) {
                $dest = $dir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($src, $dest);
                $copied[] = $rel;
            }
        }
        $data = $this->root . '/var/data';
        if (is_dir($data)) {
            $this->copyTree($data, $dir . '/var/data');
            $copied[] = 'var/data';
        }
        $uploads = $this->root . '/var/uploads';
        if (is_dir($uploads)) {
            $this->copyTree($uploads, $dir . '/var/uploads');
            $copied[] = 'var/uploads';
        }
        file_put_contents($dir . '/MANIFEST.json', json_encode([
            'created_at' => gmdate('c'),
            'copied' => $copied,
            'note' => 'Restore with php bin/console restore --from=PATH. Stop writers first.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $dir;
    }

    public function restore(string $from): void
    {
        $fromReal = realpath($from);
        $base = realpath($this->backupsDir());
        if ($fromReal === false || $base === false) {
            throw new \InvalidArgumentException('Backup path is not valid.');
        }
        $fromNorm = strtolower(str_replace('\\', '/', $fromReal));
        $baseNorm = strtolower(str_replace('\\', '/', $base));
        if (!str_starts_with($fromNorm, $baseNorm)) {
            throw new \InvalidArgumentException('Restore is only allowed from var/backups/.');
        }
        if (!is_file($fromReal . '/MANIFEST.json')) {
            throw new \InvalidArgumentException('Backup MANIFEST.json is missing.');
        }
        foreach (['.env', 'var/installed.json'] as $rel) {
            $src = $fromReal . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($src)) {
                $dest = $this->root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $parent = dirname($dest);
                if (!is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($src, $dest);
            }
        }
        if (is_dir($fromReal . '/var/data')) {
            $this->copyTree($fromReal . '/var/data', $this->root . '/var/data');
        }
        if (is_dir($fromReal . '/var/uploads')) {
            $this->copyTree($fromReal . '/var/uploads', $this->root . '/var/uploads');
        }
    }

    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException('Could not copy ' . $from);
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
