<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$path = $_ENV['SQLITE_PATH'] ?? 'data/db/lastfm.sqlite';

if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
    $path = __DIR__ . '/' . $path;
}

$dir = dirname($path);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

if (!file_exists($path)) {
    touch($path);
}

$config = [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'migrations',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'sqlite',
            'name' => $path,
            'suffix' => '',
        ],
    ],
];

return $config;
