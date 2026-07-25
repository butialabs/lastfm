<?php

return [

    'api_key' => env('LASTFM_API'),

    // Image scraping falls back to this proxy: 2 attempts via proxy, then 1 direct.
    'proxy_url' => env('LASTFM_PROXY_URL'),

    'user_agent' => env('LASTFM_USER_AGENT', 'LastFM.butialabs.com/1.0'),

    // Key of the old scheme (aes-256-cbc + HMAC), used ONLY by lastfm:import-legacy
    // to decrypt legacy credentials before re-encrypting them with APP_KEY.
    'encryption_key' => env('ENCRYPTION_KEY'),

    // Consecutive failures before giving up until next week (user returns to SCHEDULE).
    'max_error_count' => (int) env('MAX_ERROR_COUNT', 3),

    // Initial seed — see database/seeders/AdminSeeder.php.
    'admin' => [
        'username' => env('ADMIN_USER', 'admin'),
        'password' => env('ADMIN_PASSWORD', ''),
    ],

    // Alternative artist image providers (admin panel only).
    // "123" is TheAudioDB's public free-tier key.
    'theaudiodb_api_key' => env('THEAUDIODB_API_KEY', '123'),
    'fanart_api_key' => env('FANART_API_KEY'),

    'locales' => ['en', 'pt-BR', 'fr-FR'],

    'images' => [
        // Artists retried on each lastfm:schedule tick, on top of the ones in the
        // weekly charts. Lower this if Last.fm starts answering 403/429.
        'backfill_per_tick' => (int) env('IMAGE_BACKFILL_PER_TICK', 5),

        // Last.fm sometimes serves a stub image; that result expires after this
        // many days so the artist is attempted again.
        'placeholder_retry_days' => (int) env('IMAGE_PLACEHOLDER_RETRY_DAYS', 30),
    ],

    'post' => [
        // Number of artists shown in the post and in the montage.
        'chart_size' => 5,

        'protocols' => [
            'at' => [
                // Bluesky allows 300 graphemes; the rest is reserved for the thread suffix.
                'max_length' => (int) env('BLUESKY_MAX_LENGTH', 253),
                'mention' => env('BLUESKY_MENTION', '@lastfm-butialabs.bsky.social'),
            ],
            'mastodon' => [
                'max_length' => (int) env('MASTODON_MAX_LENGTH', 500),
                'mention' => env('MASTODON_MENTION', '@lfm_blue@mastodon.social'),
            ],
        ],
    ],

];
