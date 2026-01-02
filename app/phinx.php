<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$sqlitePath = $_ENV['SQLITE_PATH'] ?? 'data/db/lastfm.sqlite';
$sqliteFullPath = $sqlitePath;

if (!str_starts_with($sqliteFullPath, '/') && !preg_match('/^[A-Za-z]:\\\\/', $sqliteFullPath)) {
    $sqliteFullPath = __DIR__ . '/' . $sqliteFullPath;
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'migrations',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'sqlite',
            'name' => $sqliteFullPath,
            'suffix' => '',
        ],
    ],
];
