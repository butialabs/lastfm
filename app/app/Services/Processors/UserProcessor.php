<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\User;
use App\Services\LastFm\ArtistImageFetcher;
use App\Services\LastFm\WeeklyChartService;
use App\Services\MontageService;
use Illuminate\Support\Facades\Log;

final class UserProcessor
{
    public function __construct(
        private readonly WeeklyChartService $charts,
        private readonly ArtistImageFetcher $images,
        private readonly MontageService $montage,
    ) {}

    public function runSchedule(): void
    {
        $nowUtc = now('UTC');
        $due = User::dueForSchedule($nowUtc);

        Log::channel('user_processor')->info('user_processor tick', [
            'utc' => $nowUtc->format(DATE_ATOM),
            'count' => $due->count(),
        ]);

        foreach ($due as $user) {
            $this->processUser($user);
        }

        $backfill = $this->images->backfill();

        if ($backfill['attempted'] > 0) {
            Log::channel('artist_images')->info('Artist image backfill slice', $backfill);
        }
    }

    public function processUserById(int $userId): bool
    {
        $user = User::find($userId);
        if ($user === null) {
            Log::channel('user_processor')->error('User not found', ['user_id' => $userId]);

            return false;
        }

        Log::channel('user_processor')->info('Force processing user', ['user_id' => $userId]);

        return $this->processUser($user);
    }

    /**
     * Fetch weekly chart, resolve images and build the montage → QUEUED.
     */
    public function processUser(User $user): bool
    {
        $userId = (int) $user->id;
        Log::channel('user_processor')->info('Processing user', ['user_id' => $userId]);

        try {
            $lastfmUsername = (string) ($user->lastfm_username ?? '');

            if ($lastfmUsername === '') {
                $user->setCallback('No Last.fm username configured');

                return false;
            }

            $chart = $this->charts->forUser($lastfmUsername, userId: $userId);

            if ($chart['artists'] === []) {
                $user->setCallback('No weekly chart data');

                return false;
            }

            $paths = [];
            foreach ($chart['artists'] as $artist) {
                $paths[] = $this->images->pathFor($artist['name'], $artist['mbid']);
            }

            $montagePath = $this->montage->createWeeklyMontage($userId, $paths);

            $user->refresh()->markQueued($montagePath);
            $user->setCallback('Queued successfully');

            return true;
        } catch (\Throwable $e) {
            Log::channel('user_processor')->error('user_processor failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $user->incrementError($e->getMessage(), temporary: true, retryStatus: User::STATUS_SCHEDULE);

            return false;
        }
    }
}
