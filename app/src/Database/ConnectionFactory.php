<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectionFactory
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $basePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('PDO_SQLITE extension is not enabled (pdo_sqlite)');
        }

        $path = (string) ($_ENV['SQLITE_PATH'] ?? 'data/db/lastfm.sqlite');
        $full = $path;
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $full = $this->basePath . '/' . $path;
        }

        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        try {
            $pdo = new PDO('sqlite:' . $full);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            try {
                $this->logger->error('Failed to open SQLite database', ['path' => $full, 'error' => $e->getMessage()]);
            } catch (Throwable $logErr) {
                error_log('Failed to log DB error: ' . $logErr->getMessage());
            }
            throw $e;
        }
    }
}

