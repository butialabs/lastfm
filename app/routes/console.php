<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Process scheduled users (build montages and mark as QUEUED).
Schedule::command('lastfm:schedule')
    ->everyMinute()
    ->withoutOverlapping();

// Send the queue (posts to Bluesky/Mastodon).
Schedule::command('lastfm:send')
    ->everyMinute()
    ->withoutOverlapping();

// Download missing artist images (daily, 04:00).
Schedule::command('lastfm:images-download')
    ->dailyAt('04:00')
    ->withoutOverlapping();
