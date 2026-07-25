<?php

use Monolog\Handler\NullHandler;

// Per-process log files under the data volume, matching the legacy app's layout.
$channel = static fn (string $file): array => [
    'driver' => 'single',
    'path' => base_path('data/logs/'.$file),
    'level' => env('LOG_LEVEL', 'info'),
    'replace_placeholders' => true,
];

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => $channel('app.log'),
        'app' => $channel('app.log'),
        'queue_processor' => $channel('queue_processor.log'),
        'user_processor' => $channel('user_processor.log'),
        'artist_images' => $channel('artist_images.log'),

        // Target of the framework's default "deprecations" channel.
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => base_path('data/logs/app.log'),
        ],

    ],

];
