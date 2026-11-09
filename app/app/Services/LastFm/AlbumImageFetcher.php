<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Models\Album;
use App\Services\LastFm\Concerns\TracksImageHash;
use Illuminate\Support\Facades\Log;

/**
 * Resolves album covers: album.getinfo gives the cover URL (Last.fm still
 * serves album art through the API), the binary is downloaded through the
 * same proxy fallback as artist images and cached normalized.
 */
final class AlbumImageFetcher
{
    use TracksImageHash;

    public function __construct(
        private readonly LastFmApi $api,
        private readonly ArtistImageScraper $scraper,
        private readonly AlbumImageCache $cache,
    ) {}

    /**
     * Cover contents, downloading it on a cache miss. A failed download serves
     * the placeholder (null only if the placeholder file is missing).
     */
    public function imageFor(string $artistName, string $albumName, ?string $mbid = null): ?string
    {
        $hash = $this->cache->hashFor($artistName, $albumName);

        $album = Album::firstOrCreate(
            ['artist_name' => $artistName, 'name' => $albumName],
            ['musicbrainz_id' => $mbid, 'image_hash' => null],
        );

        if ($album->image_hash === Album::PLACEHOLDER_HASH) {
            if (! $this->placeholderExpired($album)) {
                return $this->cache->placeholderBytes();
            }
        } elseif (($bin = $this->cache->get($hash)) !== null) {
            if ($album->image_hash !== $hash) {
                $album->update(['image_hash' => $hash]);
            }

            return $bin;
        }

        if ($mbid !== null && blank($album->musicbrainz_id)) {
            $album->update(['musicbrainz_id' => $mbid]);
        }

        if ($this->fetchAndStore($album, $hash) !== self::STORED) {
            $this->rememberHash($album, Album::PLACEHOLDER_HASH);

            return $this->cache->placeholderBytes();
        }

        $this->rememberHash($album, $hash);

        return $this->cache->get($hash);
    }

    /**
     * @return self::STORED|self::PLACEHOLDER|self::FAILED
     */
    private function fetchAndStore(Album $album, string $hash): string
    {
        $context = ['artist' => $album->artist_name, 'album' => $album->name];

        try {
            $imageUrl = $this->api->albumImageUrl($album->artist_name, $album->name, $album->musicbrainz_id);
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Album info request failed', $context + ['error' => $e->getMessage()]);

            return self::FAILED;
        }

        if ($imageUrl === null || $imageUrl === '') {
            return self::FAILED;
        }

        $bin = $this->scraper->download($imageUrl, $album->artist_name, 'album-cover');
        if ($bin === null) {
            return self::FAILED;
        }

        if ($this->cache->isPlaceholder($bin)) {
            return self::PLACEHOLDER;
        }

        try {
            $this->cache->put($hash, $bin);
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Album cover could not be stored', $context + ['error' => $e->getMessage()]);

            return self::FAILED;
        }

        return self::STORED;
    }
}
