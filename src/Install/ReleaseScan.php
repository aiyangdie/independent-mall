<?php

declare(strict_types=1);

namespace IndependentMall\Install;

final class ReleaseScan
{
    /**
     * @return list<array{file:string,issue:string}>
     */
    public static function findings(string $root): array
    {
        $needles = [
            'BEGIN' . ' PRIVATE' . ' KEY' => 'private key material',
            'BEGIN' . ' OPENSSH PRIVATE' => 'private key material',
            "\u{5F69}\u{8679}" => 'legacy brand',
            "\u{4EE3}\u{5237}" => 'prohibited business',
            'ev' . 'al(' => 'dynamic code execution',
            "file_get_contents('" . 'http://' => 'remote include',
            'file_get_contents("' . 'http://' => 'remote include',
            "file_get_contents('" . 'https://' => 'remote include',
            'file_get_contents("' . 'https://' => 'remote include',
        ];
        $sqlSkip = ["\u{4EE3}\u{5237}", "\u{5F69}\u{8679}"];
        $dirs = [
            $root . '/src',
            $root . '/templates',
            $root . '/modules',
            $root . '/public',
            $root . '/database',
            $root . '/bin',
            $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'src',
            $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'templates',
            $root . DIRECTORY_SEPARATOR . 'apps' . DIRECTORY_SEPARATOR . 'license-server' . DIRECTORY_SEPARATOR . 'src',
        ];
        $hits = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getBasename() === 'ReleaseScan.php') {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'md', 'sql', 'json', 'txt', 'css', 'js'], true)) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                foreach ($needles as $needle => $issue) {
                    if ($ext === 'sql' && in_array($needle, $sqlSkip, true)) {
                        continue;
                    }
                    if (str_contains($code, $needle)) {
                        $hits[] = [
                            'file' => str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)),
                            'issue' => $issue,
                        ];
                    }
                }
            }
        }
        return $hits;
    }
}
