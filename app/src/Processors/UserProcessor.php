<?php

declare(strict_types=1);

namespace App\Processors;

use App\Repositories\UserRepository;
use App\Services\LastFmService;
use App\Services\LoggerFactory;
use App\Services\MontageService;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class UserProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LastFmService $lastfm,
        private readonly MontageService $montage,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->make('user_processor');
    }

    public function runSchedule(): void
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $due = $this->users->findUsersDueForSchedule($nowUtc);

        $this->logger->info('user_processor tick', ['utc' => $nowUtc->format(DATE_ATOM), 'count' => count($due)]);

        foreach ($due as $user) {
            $this->processUser($user);
        }
    }

    /**
     * Process a single user by ID (force mode).
     * @return bool True if successfully queued
     */
    public function processUserById(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->logger->error('User not found', ['user_id' => $userId]);
            return false;
        }

        $this->logger->info('Force processing user', ['user_id' => $userId]);
        return $this->processUser($user);
    }

    /**
     * @param array<string,mixed> $user
     * @return bool True if successfully queued
     */
    private function processUser(array $user): bool
    {
        $userId = (int) $user['id'];
        $this->logger->info('Processing user', ['user_id' => $userId]);

        try {
            $lastfmUsername = (string) ($user['lastfm_username'] ?? '');

            if ($lastfmUsername === '') {
                $this->users->setCallback($userId, 'No Last.fm username configured');
                return false;
            }

            $chart = $this->lastfm->getWeeklyArtistChart($lastfmUsername, 5);
            if ($chart === []) {
                $this->users->setCallback($userId, 'No weekly chart data');
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

            $this->users->markQueued($userId, $montagePath);
            $this->users->setCallback($userId, 'Queued successfully');
            return true;
        } catch (Throwable $e) {
            $this->logger->error('user_processor failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            $this->users->incrementError($userId, $e->getMessage(), temporary: true);
            return false;
        }
    }
}

