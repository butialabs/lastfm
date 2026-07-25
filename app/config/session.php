<?php

use Illuminate\Support\Str;

// Only the deltas from Laravel's base config; everything else is the framework default.
return [

    'driver' => env('SESSION_DRIVER', 'file'),

    // Pinned to the legacy app's cookie name — changing it logs every user out.
    'cookie' => env('SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel')).'-session'),

];
