<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processors\UserProcessor;
use Illuminate\Console\Command;

class ScheduleUsersCommand extends Command
{
    protected $signature = 'lastfm:schedule';

    protected $description = 'Process users scheduled for this minute (build montages and queue them)';

    public function handle(UserProcessor $processor): int
    {
        $processor->runSchedule();

        return self::SUCCESS;
    }
}
