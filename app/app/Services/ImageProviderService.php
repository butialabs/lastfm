<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ImageProviderService
{
    public function hasAnyProvider(): bool
    {
        return $this->theaudiodbKey() !== null || $this->fanartKey() !== null;
    }

    /**
     * @return list<array{source:string, type:string, url:string}>
     */
    public function fetchImageSources(string $artistName, ?string $mbid): array
    {
        $sources = [];

        $theaudiodbKey = $this->theaudiodbKey();
        if ($theaudiodbKey !== null) {
            $sources = array_merge($sources, $this->fetchFromTheAudioDB($theaudiodbKey, $artistName, $mbid));
        }

        $fanartKey = $this->fanartKey();
        if ($fanartKey !== null && $mbid !== null && $mbid !== '') {
            $sources = array_merge($sources, $this->fetchFromFanart($fanartKey, $mbid));
        }

        $seen = [];
        $unique = [];
        foreach ($sources as $s) {
            if (! isset($seen[$s['url']])) {
                $seen[$s['url']] = true;
                $unique[] = $s;
            }
        }

        return $unique;
    }

    private function theaudiodbKey(): ?string
    {
        $key = trim((string) config('lastfm.theaudiodb_api_key', ''));

        return $key !== '' ? $key : null;
    }

    private function fanartKey(): ?string
    {
        $key = trim((string) config('lastfm.fanart_api_key', ''));

        return $key !== '' ? $key : null;
    }

    /**
     * @return list<array{source:string, type:string, url:string}>
     */
    private function fetchFromTheAudioDB(string $apiKey, string $artistName, ?string $mbid): array
    {
        $data = null;

        if ($mbid !== null && $mbid !== '') {
            $data = $this->getJson(
                "https://www.theaudiodb.com/api/v1/json/{$apiKey}/artist-mb.php?i=".rawurlencode($mbid),
                'theaudiodb:artist-mb'
            );
        }

        if ($data === null) {
            $data = $this->getJson(
                "https://www.theaudiodb.com/api/v1/json/{$apiKey}/search.php?s=".rawurlencode($artistName),
                'theaudiodb:search'
            );
        }

        if ($data === null || empty($data['artists']) || ! is_array($data['artists'])) {
            return [];
        }

        $artist = $data['artists'][0] ?? null;
        if (! is_array($artist)) {
            return [];
        }

        $sources = [];
        $fields = [
            'strArtistThumb' => 'thumb',
            'strArtistCroppedThumb' => 'square',
            'strArtistWideThumb' => 'wide',
            'strArtistFanart' => 'fanart',
            'strArtistLogo' => 'logo',
        ];

        foreach ($fields as $field => $type) {
            $url = trim((string) ($artist[$field] ?? ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $sources[] = ['source' => 'theaudiodb', 'type' => $type, 'url' => $url];
            }
        }

        return $sources;
    }

    /**
     * @return list<array{source:string, type:string, url:string}>
     */
    private function fetchFromFanart(string $apiKey, string $mbid): array
    {
        $data = $this->getJson(
            'https://webservice.fanart.tv/v3/music/'.rawurlencode($mbid).'?api_key='.rawurlencode($apiKey),
            'fanart:music'
        );

        if ($data === null) {
            return [];
        }

        $sources = [];
        $categories = [
            'thumb' => 'thumb',
            'musicbanner' => 'banner',
            'artistbackground' => 'background',
            'hdmusiclogo' => 'hdlogo',
            'musiclogo' => 'logo',
        ];

        foreach ($categories as $category => $type) {
            $items = $data[$category] ?? null;
            if (! is_array($items)) {
                continue;
            }
            usort($items, fn ($a, $b) => (int) ($b['likes'] ?? 0) <=> (int) ($a['likes'] ?? 0));
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $url = trim((string) ($item['url'] ?? ''));
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                    $sources[] = ['source' => 'fanart', 'type' => $type, 'url' => $url];
                }
            }
        }

        return $sources;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getJson(string $url, string $context): ?array
    {
        try {
            $res = Http::withOptions(['http_errors' => false])
                ->timeout(15)
                ->connectTimeout(10)
                ->get($url);

            if ($res->status() !== 200) {
                Log::channel('app')->debug('Image provider non-200', [
                    'context' => $context,
                    'status' => $res->status(),
                ]);

                return null;
            }

            $json = $res->json();

            return is_array($json) ? $json : null;
        } catch (\Throwable $e) {
            Log::channel('app')->debug('Image provider request failed', [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
