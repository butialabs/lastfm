<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\User;
use App\Services\LastFmService;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

final class QueueProcessor
{
    public function __construct(
        private readonly LastFmService $lastfm,
        private readonly BlueskyClient $bluesky,
        private readonly MastodonClient $mastodon,
    ) {}

    public function runSend(): void
    {
        $queued = User::queued();

        Log::channel('queue_processor')->info('queue_processor tick', [
            'utc' => now('UTC')->format(DATE_ATOM),
            'count' => $queued->count(),
        ]);

        foreach ($queued as $user) {
            $this->sendForUser($user);
        }
    }

    /**
     * Send for a specific user (force mode). User must be QUEUED with a montage ready.
     */
    public function sendForUserId(int $userId): bool
    {
        $user = User::find($userId);
        if ($user === null) {
            Log::channel('queue_processor')->error('User not found', ['user_id' => $userId]);

            return false;
        }

        if ($user->status !== User::STATUS_QUEUED) {
            Log::channel('queue_processor')->error('User is not in QUEUED status', [
                'user_id' => $userId,
                'status' => $user->status ?? 'unknown',
            ]);

            return false;
        }

        Log::channel('queue_processor')->info('Force sending for user', ['user_id' => $userId]);

        return $this->sendForUser($user);
    }

    public function sendForUser(User $user): bool
    {
        $userId = (int) $user->id;
        $user->markSending();

        try {
            $protocol = (string) $user->protocol;
            $instance = (string) $user->instance;
            $language = (string) ($user->language ?? 'en');
            $montagePath = (string) ($user->social_montage ?? '');

            $text = $this->buildPostText($user, $language, $protocol);
            $threads = $this->splitTextForProtocol($text, $protocol);

            $montageFilePath = User::montageUrlToFilePath($montagePath);
            if ($montageFilePath === null || ! is_file($montageFilePath)) {
                throw new \RuntimeException('Montage file not found');
            }

            $username = (string) ($user->lastfm_username ?? '');
            $chart = $this->lastfm->getWeeklyArtistChart($username, 5);
            $altText = $this->generateAltText($chart, $language);

            if ($protocol === User::PROTOCOL_AT) {
                $password = Crypt::decryptString((string) $user->password);
                $session = $this->bluesky->createSession($instance, (string) $user->username, $password);
                $imageData = $this->bluesky->uploadImage($instance, $session['accessJwt'], $montageFilePath);

                $root = null;
                $parent = null;
                foreach ($threads as $i => $chunk) {
                    $embed = $i === 0 ? $this->bluesky->makeImageEmbed($imageData['blob'], alt: $altText, width: $imageData['width'], height: $imageData['height']) : null;
                    $result = $this->bluesky->createPost($instance, $session['did'], $session['accessJwt'], $chunk, $embed, $root, $parent);
                    $root = $root ?? ['uri' => $result['uri'], 'cid' => $result['cid']];
                    $parent = ['uri' => $result['uri'], 'cid' => $result['cid']];
                }
            } elseif ($protocol === User::PROTOCOL_MASTODON) {
                $token = Crypt::decryptString((string) $user->token);
                $mediaId = $this->mastodon->uploadMedia($instance, $token, $montageFilePath, $altText);

                $inReplyTo = null;
                foreach ($threads as $i => $chunk) {
                    $status = $this->mastodon->postStatus($instance, $token, $chunk, $i === 0 ? $mediaId : null, $inReplyTo);
                    $inReplyTo = (string) ($status['id'] ?? null);
                }
            } else {
                throw new \RuntimeException('Unknown protocol');
            }

            $user->refresh()->markScheduledAfterSend($text);
            $user->setCallback('Sent successfully');

            return true;
        } catch (\Throwable $e) {
            Log::channel('queue_processor')->error('queue_processor failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $maxErrors = (int) config('lastfm.max_error_count', 3);
            $newCount = $user->refresh()->incrementError($e->getMessage(), temporary: true);
            if ($newCount >= $maxErrors) {
                $user->markScheduledAfterGiveUp($e->getMessage());
            } else {
                $user->markQueued((string) ($user->social_montage ?? ''));
            }

            return false;
        }
    }

    private function buildPostText(User $user, string $language, string $protocol): string
    {
        $mention = $protocol === User::PROTOCOL_MASTODON ? '@lfm_blue@mastodon.social' : '@lastfm-butialabs.bsky.social';
        $username = (string) ($user->lastfm_username ?? '');

        $chart = $this->lastfm->getWeeklyArtistChart($username, 5);
        $totalScrobbles = $this->lastfm->getWeeklyTotalScrobbles($username);

        $artistParts = [];
        foreach ($chart as $a) {
            $artistParts[] = sprintf('%s (%d)', $a['name'], $a['playcount']);
        }

        $artistList = implode(' ', $artistParts);
        $scrobblesText = sprintf(__('messages.post.scrobbles', [], $language), $totalScrobbles);

        $prefix = sprintf('♫ %s: ', __('messages.post.top_artists', [], $language));
        $suffix = sprintf('. #myweekcounted %s #music %s %s', $scrobblesText, __('messages.post.via', [], $language), $mention);

        $limit = $protocol === User::PROTOCOL_MASTODON ? 500 : 253;
        $available = $limit - mb_strlen($prefix) - mb_strlen($suffix);
        if ($available > 3 && mb_strlen($artistList) > $available) {
            $artistList = rtrim(mb_substr($artistList, 0, $available - 3)).'...';
        }

        return $prefix.$artistList.$suffix;
    }

    private function generateAltText(array $chart, string $language): string
    {
        $artistNames = array_map(fn ($artist) => $artist['name'], $chart);
        $artistList = implode(', ', $artistNames);

        return sprintf(__('messages.post.alt_text', [], $language), $artistList);
    }

    /** @return list<string> */
    private function splitTextForProtocol(string $text, string $protocol): array
    {
        $limit = $protocol === User::PROTOCOL_MASTODON ? 500 : 253;
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
