<?php

declare(strict_types=1);

use App\Services\Processors\QueueProcessor;
use App\Services\Processors\UserProcessor;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(UserProcessor::class)->runSchedule())
    ->name('lastfm:schedule')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(fn () => app(QueueProcessor::class)->runSend())
    ->name('lastfm:send')
    ->everyMinute()
    ->withoutOverlapping();
