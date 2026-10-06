<?php

declare(strict_types=1);

namespace IndependentMall\Database;

use PDO;

final class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $directory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = [];
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations');
        if ($stmt !== false) {
            foreach ($stmt as $row) {
                $applied[$row['version']] = true;
            }
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        $ran = [];
        foreach ($files as $file) {
            $version = basename($file);
            if (isset($applied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Cannot read migration ' . $file);
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $ins = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)');
                $ins->execute(['v' => $version, 't' => gmdate('c')]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            $ran[] = $version;
        }
        return $ran;
    }
}
