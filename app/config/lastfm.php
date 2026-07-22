<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Last.fm
    |--------------------------------------------------------------------------
    */
    'api_key' => env('LASTFM_API'),

    // Fallback proxy for image scraping (2 attempts via proxy, then 1 direct).
    'proxy_url' => env('LASTFM_PROXY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Legacy encryption
    |--------------------------------------------------------------------------
    | Key of the old scheme (aes-256-cbc + HMAC), used ONLY by the
    | lastfm:import-legacy command to decrypt legacy credentials before
    | re-encrypting them with APP_KEY.
    */
    'encryption_key' => env('ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    | Consecutive failures before giving up until next week (user returns
    | to SCHEDULE).
    */
    'max_error_count' => (int) env('MAX_ERROR_COUNT', 3),

    /*
    |--------------------------------------------------------------------------
    | Admin (initial seed — see database/seeders/AdminSeeder.php)
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'username' => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alternative artist image providers
    |--------------------------------------------------------------------------
    */
    'theaudiodb_api_key' => env('THEAUDIODB_API_KEY', '123'),
    'fanart_api_key' => env('FANART_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Supported locales
    |--------------------------------------------------------------------------
    */
    'locales' => ['en', 'pt-BR', 'fr-FR'],

];
