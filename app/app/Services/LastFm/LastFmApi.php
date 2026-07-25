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
        $artists = $data['weeklyartistchart']['artist'] ?? [];

        if (! is_array($artists)) {
            return ['artists' => [], 'total_scrobbles' => 0];
        }

        // A chart with a single artist comes back as an object, not a list.
        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $entries = [];
        $totalScrobbles = 0;

        foreach ($artists as $a) {
            if (! is_array($a)) {
                continue;
            }

            $playcount = (int) ($a['playcount'] ?? 0);
            $totalScrobbles += $playcount;

            $name = (string) ($a['name'] ?? '');
            if ($name === '' || count($entries) >= $limit) {
                continue;
            }

            $mbid = (string) ($a['mbid'] ?? '');

            $entries[] = [
                'name' => $name,
                'playcount' => $playcount,
                'imageUrl' => is_array($a['image'] ?? null) ? $this->pickLargestImageUrl($a['image']) : null,
                'mbid' => $mbid !== '' ? $mbid : null,
            ];
        }

        return ['artists' => $entries, 'total_scrobbles' => $totalScrobbles];
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
