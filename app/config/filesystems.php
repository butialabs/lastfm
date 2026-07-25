<?php

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
        'montage' => [
            'driver' => 'local',
            'root' => base_path('data/montage'),
            'throw' => false,
            'report' => false,
        ],

        // Artist image cache ({md5(name)}.jpg).
        'artist-cache' => [
            'driver' => 'local',
            'root' => base_path('data/cache/artists'),
            'throw' => false,
            'report' => false,
        ],

    ],

];
