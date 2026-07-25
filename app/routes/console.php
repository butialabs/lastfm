<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('lastfm:schedule')->everyMinute()->withoutOverlapping();

Schedule::command('lastfm:send')->everyMinute()->withoutOverlapping();
