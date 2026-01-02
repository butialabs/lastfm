<?php

declare(strict_types=1);

namespace App\Services\Social;

use GuzzleHttp\Client as Guzzle;
use Psr\Log\LoggerInterface;

final class BlueskyClient
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function normalizeInstance(string $instance): string
    {
        $instance = trim($instance);
        if ($instance === '') {
            return 'https://bsky.social';
        }
        if (!str_starts_with($instance, 'http')) {
            $instance = 'https://' . $instance;
        }
        return rtrim($instance, '/');
    }

    /** @return array{did:string,accessJwt:string,refreshJwt:string} */
    public function createSession(string $instance, string $identifier, string $password): array
    {
        $base = $this->normalizeInstance($instance);
        $res = $this->http->post($base . '/xrpc/com.atproto.server.createSession', [
            'json' => ['identifier' => $identifier, 'password' => $password],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['did'], $json['accessJwt'], $json['refreshJwt'])) {
            throw new \RuntimeException('Invalid Bluesky session response');
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
    public function uploadImage(string $instance, string $accessJwt, string $absoluteImagePath): array
    {
        $base = $this->normalizeInstance($instance);
        $bin = file_get_contents($absoluteImagePath);
        if ($bin === false) {
            throw new \RuntimeException('Unable to read image: ' . $absoluteImagePath);
        }

        $imageInfo = getimagesize($absoluteImagePath);
        if ($imageInfo === false) {
            throw new \RuntimeException('Unable to get image dimensions: ' . $absoluteImagePath);
        }
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        $res = $this->http->post($base . '/xrpc/com.atproto.repo.uploadBlob', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessJwt,
                'Content-Type' => 'image/jpeg',
            ],
            'body' => $bin,
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['blob'])) {
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

    /**
     * Resolve a Bluesky handle to its DID
     */
    public function resolveHandle(string $instance, string $handle): ?string
    {
        $base = $this->normalizeInstance($instance);
        $handle = ltrim($handle, '@');

        try {
            $res = $this->http->get($base . '/xrpc/com.atproto.identity.resolveHandle', [
                'query' => ['handle' => $handle],
            ]);
            $json = json_decode((string) $res->getBody(), true);
            if (is_array($json) && isset($json['did'])) {
                return (string) $json['did'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to resolve handle: ' . $handle, ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Parse text and extract facets for mentions, hashtags, and URLs
     * @return array<int, array<string, mixed>>
     */
    public function parseFacets(string $instance, string $text): array
    {
        $facets = [];

        preg_match_all(
            '/(?<!\w)@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?!\w)/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $match) {
            $mention = (string) $match[0];
            $charOffset = (int) $match[1];
            $byteStart = strlen(substr($text, 0, $charOffset));
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
            $charOffset = (int) $match[1];
            $byteStart = strlen(substr($text, 0, $charOffset));
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
            $charOffset = (int) $match[1];
            $byteStart = strlen(substr($text, 0, $charOffset));
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
     * @param array<string,mixed>|null $embed
     * @param array{uri:string,cid:string}|null $root
     * @param array{uri:string,cid:string}|null $parent
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
        if (!empty($facets)) {
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

        $res = $this->http->post($base . '/xrpc/com.atproto.repo.createRecord', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessJwt,
            ],
            'json' => [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ],
        ]);
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['uri'], $json['cid'])) {
            throw new \RuntimeException('Invalid createRecord response');
        }
        return ['uri' => (string) $json['uri'], 'cid' => (string) $json['cid']];
    }
}

