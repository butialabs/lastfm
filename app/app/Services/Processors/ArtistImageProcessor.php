<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\Artist;
use App\Services\LastFmService;
use Illuminate\Support\Facades\Log;

final class ArtistImageProcessor
{
    public function __construct(
        private readonly LastFmService $lastfm,
    ) {}

    /**
     * Download images for artists without one, in batches, with anti-block jitter.
     *
     * @return array{total:int, success:int, failed:int}
     */
    public function downloadMissing(): array
    {
        $batchSize = 500;
        $offset = 0;
        $total = 0;
        $success = 0;
        $failed = 0;

        while (true) {
            $artists = Artist::query()
                ->noImage('1')
                ->orderBy('name')
                ->limit($batchSize)
                ->offset($offset)
                ->get();

            if ($artists->isEmpty()) {
                break;
            }

            $batchSuccess = 0;
            $batchFailed = 0;

            foreach ($artists as $index => $artist) {
                $total++;

                try {
                    $ok = $this->lastfm->regenerateArtistImage((int) $artist->id);
                    if ($ok) {
                        $success++;
                        $batchSuccess++;
                        Log::channel('artist_image_processor')->info('Artist image regenerated', [
                            'artist_id' => $artist->id,
                            'name' => $artist->name,
                        ]);
                    } else {
                        $failed++;
                        $batchFailed++;
                        Log::channel('artist_image_processor')->warning('Artist image regeneration failed', [
                            'artist_id' => $artist->id,
                            'name' => $artist->name,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $batchFailed++;
                    Log::channel('artist_image_processor')->error('Artist image regeneration error', [
                        'artist_id' => $artist->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($index < $artists->count() - 1) {
                    $this->sleepJitter();
                }
            }

            if ($artists->count() < $batchSize) {
                break;
            }

            if ($batchSuccess === 0) {
                $offset += $batchFailed;
            } else {
                $offset = 0;
            }

            $this->sleepJitter();
        }

        Log::channel('artist_image_processor')->info('Artist image download batch finished', [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
        ]);

        return ['total' => $total, 'success' => $success, 'failed' => $failed];
    }

    private function sleepJitter(): void
    {
        usleep(random_int(2_000_000, 5_000_000));
    }
}
