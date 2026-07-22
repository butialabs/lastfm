<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processors\QueueProcessor;
use App\Services\Processors\UserProcessor;
use Illuminate\Console\Command;

class ForceSendCommand extends Command
{
    protected $signature = 'lastfm:force-send {user : User ID}';

    protected $description = 'Force immediate processing and sending for a single user';

    public function handle(UserProcessor $userProcessor, QueueProcessor $queueProcessor): int
    {
        $userId = (int) $this->argument('user');

        $this->info("Step 1/2: building montage for user #{$userId}...");
        if (! $userProcessor->processUserById($userId)) {
            $this->error('Failed to process the user (check user_processor.log).');

            return self::FAILURE;
        }

        $this->info('Step 2/2: sending...');
        if (! $queueProcessor->sendForUserId($userId)) {
            $this->error('Failed to send (check queue_processor.log).');

            return self::FAILURE;
        }

        $this->info('Sent successfully.');

        return self::SUCCESS;
    }
}
