<?php

declare(strict_types=1);

namespace App\Services\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class BlueskyClient
{
    private const APP_USER_AGENT = 'LastFM.butialabs.com/1.0';

    public function normalizeInstance(string $instance): string
    {
        $instance = trim($instance);
        if ($instance === '') {
            return 'https://bsky.social';
        }
        if (! str_starts_with($instance, 'http')) {
            $instance = 'https://'.$instance;
        }

        return rtrim($instance, '/');
    }

    /** @return array{did:string,accessJwt:string,refreshJwt:string} */
    public function createSession(string $instance, string $identifier, string $password): array
    {
        $base = $this->normalizeInstance($instance);

        try {
            $res = $this->request()->post($base.'/xrpc/com.atproto.server.createSession', [
                'identifier' => $identifier,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            throw new SocialAuthException(SocialAuthException::UNREACHABLE, $e->getMessage(), $e);
        }

        $json = $res->json();

        // PDS answers 401 (AuthenticationRequired) or 400 with an XRPC error for bad credentials.
        if (in_array($res->status(), [400, 401], true) && is_array($json) && isset($json['error'])) {
            throw new SocialAuthException(SocialAuthException::REJECTED, (string) ($json['message'] ?? $json['error']));
        }

        if (! is_array($json) || ! isset($json['did'], $json['accessJwt'], $json['refreshJwt'])) {
            throw new SocialAuthException(SocialAuthException::NOT_A_SERVER, 'Invalid Bluesky session response');
        }

        return [
            'did' => (string) $json['did'],
            'accessJwt' => (string) $json['accessJwt'],
            'refreshJwt' => (string) $json['refreshJwt'],
        ];
    }

    /**
     * @return array{blob: array<string,mixed>, width: int, height: int}
     */
    public function uploadImage(string $instance, string $accessJwt, string $imageBinary): array
    {
        $base = $this->normalizeInstance($instance);

        $imageInfo = @getimagesizefromstring($imageBinary);
        if ($imageInfo === false) {
            throw new \RuntimeException('Unable to get image dimensions');
        }
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        $res = $this->request()
            ->withToken($accessJwt)
            ->withBody($imageBinary, (string) ($imageInfo['mime'] ?? 'image/jpeg'))
            ->post($base.'/xrpc/com.atproto.repo.uploadBlob');

        $json = $res->json();
        if (! is_array($json) || ! isset($json['blob'])) {
            throw new \RuntimeException('Invalid uploadBlob response');
        }

        return [
            'blob' => $json['blob'],
            'width' => $width,
            'height' => $height,
        ];
    }

    /** @return array<string,mixed> */
    public function makeImageEmbed(array $blob, string $alt, int $width, int $height): array
    {
        return [
            '$type' => 'app.bsky.embed.images',
            'images' => [
                [
                    'alt' => $alt,
                    'image' => $blob,
                    'aspectRatio' => [
                        'width' => $width,
                        'height' => $height,
                    ],
                ],
            ],
        ];
    }

    public function resolveHandle(string $instance, string $handle): ?string
    {
        $base = $this->normalizeInstance($instance);
        $handle = ltrim($handle, '@');

        try {
            $res = $this->request()->get($base.'/xrpc/com.atproto.identity.resolveHandle', [
                'handle' => $handle,
            ]);
            $json = $res->json();
            if (is_array($json) && isset($json['did'])) {
                return (string) $json['did'];
            }
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Failed to resolve handle: '.$handle, ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extract facets for mentions, hashtags and URLs from the text.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseFacets(string $instance, string $text): array
    {
        // Bluesky indexes facets by byte offset, which is exactly what
        // preg_match_all reports with /u + PREG_OFFSET_CAPTURE. Do not convert
        // these offsets with mb_* functions.
        $facets = [];

        preg_match_all(
            '/(?<!\w)@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?!\w)/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $match) {
            $mention = (string) $match[0];
            $byteStart = (int) $match[1];
            $byteEnd = $byteStart + strlen($mention);

            $did = $this->resolveHandle($instance, $mention);
            if ($did !== null) {
                $facets[] = [
                    'index' => [
                        'byteStart' => $byteStart,
                        'byteEnd' => $byteEnd,
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#mention',
                            'did' => $did,
                        ],
                    ],
                ];
            }
        }

        preg_match_all(
            '/(?<!\w)#([a-zA-Z0-9_\x{00C0}-\x{017F}]+)(?!\w)/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $index => $match) {
            $hashtag = (string) $match[0];
            $byteStart = (int) $match[1];
            $byteEnd = $byteStart + strlen($hashtag);
            $tag = (string) $matches[1][$index][0];

            $facets[] = [
                'index' => [
                    'byteStart' => $byteStart,
                    'byteEnd' => $byteEnd,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag' => $tag,
                    ],
                ],
            ];
        }

        preg_match_all(
            '/https?:\/\/[^\s<>\[\]()"\'\x{00A0}]+[^\s<>\[\]()"\'\x{00A0}.,!?:;]/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $match) {
            $url = (string) $match[0];
            $byteStart = (int) $match[1];
            $byteEnd = $byteStart + strlen($url);

            $facets[] = [
                'index' => [
                    'byteStart' => $byteStart,
                    'byteEnd' => $byteEnd,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri' => $url,
                    ],
                ],
            ];
        }

        return $facets;
    }

    /**
     * @param  array<string,mixed>|null  $embed
     * @param  array{uri:string,cid:string}|null  $root
     * @param  array{uri:string,cid:string}|null  $parent
     * @return array{uri:string,cid:string}
     */
    public function createPost(string $instance, string $did, string $accessJwt, string $text, ?array $embed, ?array $root, ?array $parent): array
    {
        $base = $this->normalizeInstance($instance);

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('c'),
        ];

        $facets = $this->parseFacets($instance, $text);
        if (! empty($facets)) {
            $record['facets'] = $facets;
        }

        if ($embed !== null) {
            $record['embed'] = $embed;
        }
        if ($root !== null && $parent !== null) {
            $record['reply'] = [
                'root' => $root,
                'parent' => $parent,
            ];
        }

        $res = $this->request()
            ->withToken($accessJwt)
            ->post($base.'/xrpc/com.atproto.repo.createRecord', [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ]);

        $json = $res->json();
        if (! is_array($json) || ! isset($json['uri'], $json['cid'])) {
            throw new \RuntimeException('Invalid createRecord response');
        }

        return ['uri' => (string) $json['uri'], 'cid' => (string) $json['cid']];
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent(self::APP_USER_AGENT)
            ->withOptions(['http_errors' => false])
            ->timeout(25)
            ->connectTimeout(15);
    }
}
