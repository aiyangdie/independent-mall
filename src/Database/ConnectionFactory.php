<?php

declare(strict_types=1);

namespace IndependentMall\Database;

use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    public static function make(string $databaseUrl): PDO
    {
        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            $path = substr($databaseUrl, strlen('sqlite:///'));
            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new RuntimeException('Cannot create database directory: ' . $dir);
                }
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }

        if (str_starts_with($databaseUrl, 'sqlite::memory:') || $databaseUrl === 'sqlite:///:memory:') {
            $pdo = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }

        if (preg_match('#^mysql://([^:]+):([^@]*)@([^:/]+):(\d+)/(.+)$#', $databaseUrl, $m)) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $m[3], $m[4], $m[5]);
            return new PDO($dsn, rawurldecode($m[1]), rawurldecode($m[2]), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        throw new PDOException('Unsupported DATABASE_URL');
    }
}
