<?php

declare(strict_types=1);

namespace App\Processors;

use App\Repositories\UserRepository;
use App\Services\CryptoService;
use App\Services\LastFmService;
use App\Services\LoggerFactory;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

final class QueueProcessor
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly UserRepository $users,
        private readonly CryptoService $crypto,
        private readonly LastFmService $lastfm,
        private readonly BlueskyClient $bluesky,
        private readonly MastodonClient $mastodon,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->make('queue_processor');
    }

    public function runSend(): void
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queued = $this->users->findQueuedUsers();

        $this->logger->info('queue_processor tick', ['utc' => $nowUtc->format(DATE_ATOM), 'count' => count($queued)]);

        foreach ($queued as $user) {
            $this->sendForUser($user);
        }
    }

    /**
     * Send message for a specific user by ID (force mode).
     * The user must be in QUEUED status with a montage ready.
     * @return bool True if sent successfully
     */
    public function sendForUserId(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->logger->error('User not found', ['user_id' => $userId]);
            return false;
        }

        if (($user['status'] ?? '') !== 'QUEUED') {
            $this->logger->error('User is not in QUEUED status', ['user_id' => $userId, 'status' => $user['status'] ?? 'unknown']);
            return false;
        }

        $this->logger->info('Force sending for user', ['user_id' => $userId]);
        return $this->sendForUser($user);
    }

    /**
     * @param array<string,mixed> $user
     * @return bool True if sent successfully
     */
    private function sendForUser(array $user): bool
    {
        $userId = (int) $user['id'];
        $this->users->markSending($userId);

        try {
            $protocol = (string) $user['protocol'];
            $instance = (string) $user['instance'];
            $language = (string) ($user['language'] ?? 'en');
            $montagePath = (string) ($user['social_montage'] ?? '');

            $text = $this->buildPostText($user, $language);
            $threads = $this->splitTextForProtocol($text, $protocol);

            $montageFilePath = $this->users->montageUrlToFilePath($montagePath);
            if ($montageFilePath === null || !is_file($montageFilePath)) {
                throw new \RuntimeException('Montage file not found');
            }

            if ($protocol === 'at') {
                $password = $this->crypto->decrypt((string) $user['password']);
                $session = $this->bluesky->createSession($instance, (string) $user['username'], $password);
                $imageData = $this->bluesky->uploadImage($instance, $session['accessJwt'], $montageFilePath);

                $root = null;
                $parent = null;
                foreach ($threads as $i => $chunk) {
                    $embed = $i === 0 ? $this->bluesky->makeImageEmbed($imageData['blob'], alt: 'Weekly chart', width: $imageData['width'], height: $imageData['height']) : null;
                    $result = $this->bluesky->createPost($instance, $session['did'], $session['accessJwt'], $chunk, $embed, $root, $parent);
                    $root = $root ?? ['uri' => $result['uri'], 'cid' => $result['cid']];
                    $parent = ['uri' => $result['uri'], 'cid' => $result['cid']];
                }
            } elseif ($protocol === 'mastodon') {
                $token = $this->crypto->decrypt((string) $user['token']);
                $mediaId = $this->mastodon->uploadMedia($instance, $token, $montageFilePath);

                $inReplyTo = null;
                foreach ($threads as $i => $chunk) {
                    $status = $this->mastodon->postStatus($instance, $token, $chunk, $i === 0 ? $mediaId : null, $inReplyTo);
                    $inReplyTo = (string) ($status['id'] ?? null);
                }
            } else {
                throw new \RuntimeException('Unknown protocol');
            }

            $this->users->markScheduledAfterSend($userId, $text);
            $this->users->setCallback($userId, 'Sent successfully');
            return true;
        } catch (Throwable $e) {
            $this->logger->error('queue_processor failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            $maxErrors = (int) ($_ENV['MAX_ERROR_COUNT'] ?? 3);
            $newCount = $this->users->incrementError($userId, $e->getMessage(), temporary: true);
            if ($newCount >= $maxErrors) {
                $this->users->markScheduledAfterGiveUp($userId, $e->getMessage());
            } else {
                $this->users->markQueued($userId, (string) ($user['social_montage'] ?? ''));
            }
            return false;
        }
    }

    /** @param array<string,mixed> $user */
    private function buildPostText(array $user, string $language): string
    {
        $proto = (string) $user['protocol'];
        $mention = $proto === 'mastodon' ? '@lfm_blue@mastodon.social' : '@lastfm.blue';
        $username = (string) ($user['lastfm_username'] ?? '');

        $chart = $this->lastfm->getWeeklyArtistChart($username, 5);
        $artistParts = [];
        $totalScrobbles = 0;

        foreach ($chart as $a) {
            $artistParts[] = sprintf('%s (%d)', $a['name'], $a['playcount']);
            $totalScrobbles += (int) $a['playcount'];
        }

        $artistList = implode(' ', $artistParts);
        $scrobblesText = __('post.scrobbles', [$totalScrobbles], $language);

        return sprintf(
            '♫ %s: %s. #myweekcounted %s #music %s %s',
            __('post.top_artists', [], $language),
            $artistList,
            $scrobblesText,
            __('post.via', [], $language),
            $mention
        );
    }

    /** @return list<string> */
    private function splitTextForProtocol(string $text, string $protocol): array
    {
        $limit = $protocol === 'mastodon' ? 500 : 300;
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;
        while ($remaining !== '') {
            $piece = mb_substr($remaining, 0, $limit);
            $breakPos = mb_strrpos($piece, "\n");
            if ($breakPos !== false && $breakPos > 20) {
                $piece = mb_substr($piece, 0, $breakPos);
            }
            $chunks[] = trim($piece);
            $remaining = ltrim(mb_substr($remaining, mb_strlen($piece)));
        }

        return array_values(array_filter($chunks, static fn ($c) => $c !== ''));
    }
}

