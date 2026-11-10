<?php

$imageDisk = static function (string $localRoot, string $prefix): array {
    if (env('IMAGE_STORAGE', 'local') !== 's3') {
        return [
            'driver' => 'local',
            'root' => base_path($localRoot),
            'throw' => false,
            'report' => false,
        ];
    }

    $base = trim((string) env('AWS_ROOT', ''), '/');

    return [
        'driver' => 's3',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'root' => $base !== '' ? $base.'/'.$prefix : $prefix,
        'visibility' => 'private',
        'throw' => false,
        'report' => true,
    ];
};

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Generated weekly montages, served by the /montage/{hash} route.
        'montage' => $imageDisk('data/montage', 'montage'),

        'artist-cache' => $imageDisk('data/cache/artists', 'artists'),

        'album-cache' => $imageDisk('data/cache/albums', 'albums'),

        'montage-local' => ['driver' => 'local', 'root' => base_path('data/montage'), 'throw' => false, 'report' => false],
        'artist-cache-local' => ['driver' => 'local', 'root' => base_path('data/cache/artists'), 'throw' => false, 'report' => false],
        'album-cache-local' => ['driver' => 'local', 'root' => base_path('data/cache/albums'), 'throw' => false, 'report' => false],

    ],

];
