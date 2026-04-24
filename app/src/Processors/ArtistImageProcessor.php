<?php

declare(strict_types=1);

namespace App\Processors;

use App\Repositories\ArtistRepository;
use App\Services\LastFmService;
use App\Services\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

final class ArtistImageProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly ArtistRepository $artists,
        private readonly LastFmService $lastfm,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->make('artist_image_processor');
    }

    public function downloadMissing(): array
    {
        $batchSize = 500;
        $offset = 0;
        $total = 0;
        $success = 0;
        $failed = 0;

        while (true) {
            $artists = $this->artists->findAll(['no_image' => '1'], $batchSize, $offset);
            if (empty($artists)) {
                break;
            }

            $batchSuccess = 0;
            $batchFailed = 0;

            foreach ($artists as $index => $artist) {
                $artistId = (int) $artist['id'];
                $total++;

                try {
                    $ok = $this->lastfm->regenerateArtistImage($artistId);
                    if ($ok) {
                        $success++;
                        $batchSuccess++;
                        $this->logger->info('Artist image regenerated', ['artist_id' => $artistId, 'name' => $artist['name'] ?? null]);
                    } else {
                        $failed++;
                        $batchFailed++;
                        $this->logger->warning('Artist image regeneration failed', ['artist_id' => $artistId, 'name' => $artist['name'] ?? null]);
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $batchFailed++;
                    $this->logger->error('Artist image regeneration error', [
                        'artist_id' => $artistId,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($index < \count($artists) - 1) {
                    $this->sleepJitter();
                }
            }

            if (\count($artists) < $batchSize) {
                break;
            }
            
            if ($batchSuccess === 0) {
                $offset += $batchFailed;
            } else {
                $offset = 0;
            }

            $this->sleepJitter();
        }

        $this->logger->info('Artist image download batch finished', [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
        ]);

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    private function sleepJitter(): void
    {
        $micros = random_int(2_000_000, 5_000_000);
        usleep($micros);
    }
}
