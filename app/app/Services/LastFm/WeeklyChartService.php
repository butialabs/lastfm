<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Models\Artist;

/**
 * Fetches a user's weekly chart and persists the artists and their positions.
 */
final class WeeklyChartService
{
    public function __construct(private readonly LastFmApi $api) {}

    /**
     * Passing $userId also records an ArtistStat row per returned artist.
     *
     * @return array{artists: list<array{name:string,playcount:int,imageUrl:?string,mbid:?string}>, total_scrobbles: int}
     */
    public function forUser(string $username, ?int $limit = null, ?int $userId = null): array
    {
        $chart = $this->api->weeklyChart($username, $limit ?? (int) config('lastfm.post.chart_size', 5));

        $this->syncArtists($chart['artists'], $userId);

        return $chart;
    }

    /**
     * @param  list<array{name:string,playcount:int,imageUrl:?string,mbid:?string}>  $entries
     */
    private function syncArtists(array $entries, ?int $userId): void
    {
        if ($entries === []) {
            return;
        }

        $known = Artist::query()
            ->whereIn('name', array_column($entries, 'name'))
            ->get()
            ->keyBy('name');

        foreach ($entries as $index => $entry) {
            // firstOrCreate retries on the artists.name unique constraint, so
            // concurrent scheduler ticks cannot collide here.
            $artist = $known->get($entry['name']) ?? Artist::firstOrCreate(
                ['name' => $entry['name']],
                [
                    'lastfm_url' => ArtistUrl::for($entry['name']),
                    'musicbrainz_id' => $entry['mbid'],
                    'image_hash' => null,
                ]
            );

            if ($entry['mbid'] !== null && blank($artist->musicbrainz_id)) {
                $artist->update(['musicbrainz_id' => $entry['mbid']]);
            }

            if ($userId !== null) {
                $artist->recordStats($userId, $index + 1, $entry['playcount']);
            }
        }
    }
}
