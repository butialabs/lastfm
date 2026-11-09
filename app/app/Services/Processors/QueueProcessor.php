<?php

declare(strict_types=1);

namespace App\Services\Processors;

use App\Models\User;
use App\Services\LastFm\WeeklyChartService;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

final class QueueProcessor
{
    public function __construct(
        private readonly WeeklyChartService $charts,
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
     * Force mode: the user must already be QUEUED with a montage ready.
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

            $montage = User::montageContents($montagePath);
            if ($montage === null) {
                throw new \RuntimeException('Montage file not found');
            }

            $post = $this->chartForPost($user);

            $text = $this->buildPostText($post, $language, $protocol);
            $threads = $this->splitText($text, $protocol);
            $altText = $this->generateAltText($post, $language);

            if ($protocol === User::PROTOCOL_AT) {
                $password = Crypt::decryptString((string) $user->password);
                $session = $this->bluesky->createSession($instance, (string) $user->username, $password);
                $imageData = $this->bluesky->uploadImage($instance, $session['accessJwt'], $montage);

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
                $mediaId = $this->mastodon->uploadMedia($instance, $token, $montage, $altText);

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

            $attempts = $user->refresh()->registerSendAttemptFailure($e->getMessage());
            if ($attempts >= (int) config('lastfm.max_send_attempts', 3)) {
                $user->registerWeeklyFailure('Giving up until next week: '.$e->getMessage());
            }

            return false;
        }
    }

    /**
     * The same chart the montage was built from, flattened for the post.
     *
     * @return array{mode: string, items: list<array{name:string,playcount:int}>, total_scrobbles: int}
     */
    private function chartForPost(User $user): array
    {
        $username = (string) ($user->lastfm_username ?? '');
        $size = $user->gridSize();

        if ($user->gridSource() === User::GRID_ALBUM) {
            $chart = $this->charts->albumsForUser($username, $size);
            $items = array_map(static fn (array $a) => [
                'name' => $a['name'].' – '.$a['artist'],
                'playcount' => $a['playcount'],
            ], $chart['albums']);
        } else {
            $chart = $this->charts->forUser($username, $size);
            $items = array_map(static fn (array $a) => [
                'name' => $a['name'],
                'playcount' => $a['playcount'],
            ], $chart['artists']);
        }

        return [
            'mode' => $user->gridSource(),
            'items' => $items,
            'total_scrobbles' => $chart['total_scrobbles'],
        ];
    }

    /**
     * @param  array{mode: string, items: list<array{name:string,playcount:int}>, total_scrobbles: int}  $post
     */
    private function buildPostText(array $post, string $language, string $protocol): string
    {
        $parts = [];
        foreach ($post['items'] as $item) {
            $parts[] = sprintf('%s (%d)', $item['name'], $item['playcount']);
        }

        $list = implode(' ', $parts);
        $scrobblesText = sprintf(__('messages.post.scrobbles', [], $language), $post['total_scrobbles']);

        $titleKey = $post['mode'] === User::GRID_ALBUM ? 'messages.post.top_albums' : 'messages.post.top_artists';
        $prefix = sprintf('♫ %s: ', sprintf(__($titleKey, [], $language), count($post['items'])));
        $suffix = sprintf(
            '. #myweekcounted %s #music %s %s',
            $scrobblesText,
            __('messages.post.via', [], $language),
            $this->protocolSetting($protocol, 'mention')
        );

        $available = $this->maxLength($protocol) - mb_strlen($prefix) - mb_strlen($suffix);
        if ($available > 3 && mb_strlen($list) > $available) {
            $list = rtrim(mb_substr($list, 0, $available - 3)).'...';
        }

        return $prefix.$list.$suffix;
    }

    /**
     * @param  array{mode: string, items: list<array{name:string}>}  $post
     */
    private function generateAltText(array $post, string $language): string
    {
        $key = $post['mode'] === User::GRID_ALBUM ? 'messages.post.alt_text_albums' : 'messages.post.alt_text';

        return sprintf(__($key, [], $language), implode(', ', array_column($post['items'], 'name')));
    }

    private function protocolSetting(string $protocol, string $key): string
    {
        return (string) config("lastfm.post.protocols.{$protocol}.{$key}", '');
    }

    private function maxLength(string $protocol): int
    {
        return (int) config("lastfm.post.protocols.{$protocol}.max_length", 253);
    }

    /** @return list<string> */
    private function splitText(string $text, string $protocol): array
    {
        $limit = $this->maxLength($protocol);
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
