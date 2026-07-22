<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\User;
use App\Services\LastFmService;
use App\Services\MontageService;
use Illuminate\Support\Facades\Log;

final class UserProcessor
{
    public function __construct(
        private readonly LastFmService $lastfm,
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
    }

    /**
     * Process a single user by ID (force mode).
     */
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

            $chart = $this->lastfm->getWeeklyArtistChart($lastfmUsername, 5, $userId);
            if ($chart === []) {
                $user->setCallback('No weekly chart data');

                return false;
            }

            $paths = [];
            foreach ($chart as $artist) {
                $paths[] = $this->lastfm->getArtistImagePath(
                    artistName: (string) $artist['name'],
                    imageUrl: $artist['imageUrl'] ?? null,
                    mbid: $artist['mbid'] ?? null,
                );
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
