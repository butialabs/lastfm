<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processors\QueueProcessor;
use Illuminate\Console\Command;

class SendQueuedCommand extends Command
{
    protected $signature = 'lastfm:send';

    protected $description = 'Send queued posts (QUEUED) to Bluesky/Mastodon';

    public function handle(QueueProcessor $processor): int
    {
        $processor->runSend();

        return self::SUCCESS;
    }
}
