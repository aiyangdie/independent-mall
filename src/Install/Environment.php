<?php

declare(strict_types=1);

namespace IndependentMall\Install;

final class Environment
{
    /**
     * @return list<array{id:string,ok:bool,label:string,detail:string}>
     */
    public static function checks(string $root): array
    {
        $dirs = [
            $root . '/var',
            $root . '/var/data',
            $root . '/var/sessions',
            $root . '/var/uploads',
            $root . '/var/cache',
        ];
        $out = [];
        $out[] = [
            'id' => 'php',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'label' => 'PHP 8.2+',
            'detail' => PHP_VERSION,
        ];
        foreach (['pdo', 'openssl', 'mbstring'] as $ext) {
            $out[] = [
                'id' => 'ext_' . $ext,
                'ok' => extension_loaded($ext),
                'label' => 'ext-' . $ext,
                'detail' => extension_loaded($ext) ? 'loaded' : 'missing',
            ];
        }
        $sqlite = extension_loaded('pdo_sqlite');
        $mysql = extension_loaded('pdo_mysql');
        $out[] = [
            'id' => 'pdo_driver',
            'ok' => $sqlite || $mysql,
            'label' => 'PDO SQLite or MySQL',
            'detail' => 'sqlite=' . ($sqlite ? 'yes' : 'no') . ' mysql=' . ($mysql ? 'yes' : 'no'),
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $ok = is_dir($dir) && is_writable($dir);
            $out[] = [
                'id' => 'dir_' . basename($dir),
                'ok' => $ok,
                'label' => 'Writable ' . str_replace($root . '/', '', str_replace('\\', '/', $dir)),
                'detail' => $ok ? 'writable' : 'not writable',
            ];
        }
        $out[] = [
            'id' => 'root',
            'ok' => is_writable($root),
            'label' => 'Writable application root (for .env)',
            'detail' => is_writable($root) ? 'writable' : 'not writable',
        ];
        return $out;
    }

    /**
     * @param list<array{ok:bool}> $checks
     */
    public static function allOk(array $checks): bool
    {
        foreach ($checks as $row) {
            if (!$row['ok']) {
                return false;
            }
        }
        return true;
    }
}
