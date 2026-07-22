<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Processors\ArtistImageProcessor;
use Illuminate\Console\Command;

class DownloadImagesCommand extends Command
{
    protected $signature = 'lastfm:images-download';

    protected $description = 'Download missing artist images (batched, with anti-block jitter)';

    public function handle(ArtistImageProcessor $processor): int
    {
        $result = $processor->downloadMissing();

        $this->info("Total: {$result['total']} | Success: {$result['success']} | Failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
