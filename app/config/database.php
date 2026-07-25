<?php

// Relative paths resolve against the application root so the data volume stays portable.
$resolvePath = static function (string $path): string {
    if ($path === ':memory:' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return base_path($path);
};

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => $resolvePath(env('DB_DATABASE', 'data/db/database.db')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'DEFERRED',
        ],

        // Pre-Laravel database, read only by lastfm:import-legacy.
        'legacy' => [
            'driver' => 'sqlite',
            'database' => $resolvePath(env('SQLITE_PATH', 'data/db/lastfm.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 5000,
        ],

    ],

];
