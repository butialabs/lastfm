<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use Illuminate\Http\Client\Response;

/**
 * Thin client over the Last.fm JSON API. Performs no persistence.
 */
final class LastFmApi
{
    public function __construct(private readonly ProxiedHttp $http) {}

    public function userExists(string $username): bool
    {
        try {
            $data = $this->call('user.getinfo', ['user' => $username]);
        } catch (\RuntimeException $e) {
            // API-level errors (e.g. unknown user) → invalid. Transport errors propagate.
            if (str_starts_with($e->getMessage(), 'Last.fm error:')) {
                return false;
            }

            throw $e;
        }

        return isset($data['user']['name']);
    }

    /**
     * Top artists of the week, plus the scrobble total across the *whole* chart
     * (not just the returned slice). A single API call serves both.
     *
     * @return array{artists: list<array{name:string,playcount:int,imageUrl:?string,mbid:?string}>, total_scrobbles: int}
     */
    public function weeklyChart(string $username, int $limit): array
    {
        $data = $this->call('user.getweeklyartistchart', ['user' => $username]);

        [$entries, $total] = $this->parseChart(
            $data['weeklyartistchart']['artist'] ?? [],
            $limit,
            fn (array $a, string $name, int $playcount, ?string $mbid) => [
                'name' => $name,
                'playcount' => $playcount,
                'imageUrl' => is_array($a['image'] ?? null) ? $this->pickLargestImageUrl($a['image']) : null,
                'mbid' => $mbid,
            ],
        );

        return ['artists' => $entries, 'total_scrobbles' => $total];
    }

    /**
     * Top albums of the week, plus the scrobble total across the whole chart.
     *
     * @return array{albums: list<array{name:string,artist:string,playcount:int,mbid:?string}>, total_scrobbles: int}
     */
    public function weeklyAlbumChart(string $username, int $limit): array
    {
        $data = $this->call('user.getweeklyalbumchart', ['user' => $username]);

        [$entries, $total] = $this->parseChart(
            $data['weeklyalbumchart']['album'] ?? [],
            $limit,
            static function (array $a, string $name, int $playcount, ?string $mbid): ?array {
                $artist = $a['artist'] ?? '';
                $artist = trim((string) (is_array($artist) ? ($artist['#text'] ?? $artist['name'] ?? '') : $artist));

                if ($artist === '') {
                    return null;
                }

                return ['name' => $name, 'artist' => $artist, 'playcount' => $playcount, 'mbid' => $mbid];
            },
        );

        return ['albums' => $entries, 'total_scrobbles' => $total];
    }

    /**
     * Largest cover URL Last.fm has for an album (null when it has none).
     */
    public function albumImageUrl(string $artist, string $album, ?string $mbid = null): ?string
    {
        try {
            $data = $this->call('album.getinfo', $mbid !== null && $mbid !== ''
                ? ['mbid' => $mbid]
                : ['artist' => $artist, 'album' => $album, 'autocorrect' => 1]);
        } catch (\RuntimeException $e) {
            // An unknown mbid is common; retry by name before giving up.
            if ($mbid !== null && $mbid !== '' && str_starts_with($e->getMessage(), 'Last.fm error:')) {
                return $this->albumImageUrl($artist, $album);
            }

            if (str_starts_with($e->getMessage(), 'Last.fm error:')) {
                return null;
            }

            throw $e;
        }

        return $this->pickLargestImageUrl($data['album']['image'] ?? null);
    }

    /**
     * Shared chart parsing: normalizes the single-entry object form, sums the
     * playcount of every entry and maps up to $limit named entries.
     *
     * @template T of array
     *
     * @param  callable(array<string,mixed>, string, int, ?string): ?T  $map
     * @return array{0: list<T>, 1: int}
     */
    private function parseChart(mixed $items, int $limit, callable $map): array
    {
        if (! is_array($items)) {
            return [[], 0];
        }

        // A chart with a single entry comes back as an object, not a list.
        if (isset($items['name'])) {
            $items = [$items];
        }

        $entries = [];
        $total = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $playcount = (int) ($item['playcount'] ?? 0);
            $total += $playcount;

            $name = (string) ($item['name'] ?? '');
            if ($name === '' || count($entries) >= $limit) {
                continue;
            }

            $mbid = (string) ($item['mbid'] ?? '');
            $entry = $map($item, $name, $playcount, $mbid !== '' ? $mbid : null);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return [$entries, $total];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function call(string $method, array $params): array
    {
        $apiKey = (string) config('lastfm.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('LASTFM_API is not configured');
        }

        $res = $this->http->get(
            'https://ws.audioscrobbler.com/2.0/',
            [
                'query' => array_merge($params, [
                    'method' => $method,
                    'api_key' => $apiKey,
                    'format' => 'json',
                ]),
                'headers' => ['User-Agent' => (string) config('lastfm.user_agent')],
                'timeout' => 25,
                'connect_timeout' => 15,
                'http_errors' => false,
            ],
            static fn (Response $r) => $r->status() >= 200 && $r->status() < 400,
            'api:'.$method,
            ['method' => $method]
        );

        if ($res === null) {
            throw new \RuntimeException('Last.fm request failed for '.$method);
        }

        $json = $res->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Last.fm returned invalid JSON');
        }

        // The "Last.fm error:" prefix is load-bearing — userExists() matches on it.
        if (isset($json['error'])) {
            throw new \RuntimeException('Last.fm error: '.(string) ($json['message'] ?? 'unknown'));
        }

        return $json;
    }

    private function pickLargestImageUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        $best = null;
        foreach ($images as $img) {
            if (is_array($img) && ($u = (string) ($img['#text'] ?? '')) !== '') {
                $best = $u;
            }
        }

        return $best;
    }
}
