<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\User;
use App\Services\LastFm\AlbumImageFetcher;
use App\Services\LastFm\ArtistImageFetcher;
use App\Services\LastFm\WeeklyChartService;
use App\Services\MontageService;
use Illuminate\Support\Facades\Log;

final class UserProcessor
{
    public function __construct(
        private readonly WeeklyChartService $charts,
        private readonly ArtistImageFetcher $artistImages,
        private readonly AlbumImageFetcher $albumImages,
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
     * Fetch the weekly chart (artists or albums), resolve images and build the montage → QUEUED.
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

            $size = $user->gridSize();

            if ($user->gridSource() === User::GRID_ALBUM) {
                $chart = $this->charts->albumsForUser($lastfmUsername, $size, $userId);
                $images = array_map(
                    fn (array $album) => $this->albumImages->imageFor($album['artist'], $album['name'], $album['mbid']),
                    $chart['albums'],
                );
            } else {
                $chart = $this->charts->forUser($lastfmUsername, $size, $userId);
                $images = array_map(
                    fn (array $artist) => $this->artistImages->imageFor($artist['name'], $artist['mbid']),
                    $chart['artists'],
                );
            }

            if ($images === []) {
                $user->setCallback('No weekly chart data');

                return false;
            }

            $montagePath = $this->montage->createWeeklyMontage($userId, $images, $size);

            $user->refresh()->markQueued($montagePath, resetAttempts: true);
            $user->setCallback('Queued successfully');

            return true;
        } catch (\Throwable $e) {
            Log::channel('user_processor')->error('user_processor failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $user->refresh()->registerWeeklyFailure($e->getMessage());

            return false;
        }
    }
}
