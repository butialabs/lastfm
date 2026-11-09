<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Models\Artist;
use App\Services\LastFm\Concerns\TracksImageHash;
use Illuminate\Support\Facades\Log;

/**
 * Resolves artist images, coordinating the scraper, the image cache and the
 * artists.image_hash bookkeeping (see TracksImageHash). Images are returned as
 * binary contents so the cache can live on any disk (local or S3).
 */
final class ArtistImageFetcher
{
    use TracksImageHash;

    public function __construct(
        private readonly ArtistImageScraper $scraper,
        private readonly ArtistImageCache $cache,
    ) {}

    /**
     * Cached image contents, without triggering any download.
     */
    public function cachedImage(Artist $artist): ?string
    {
        $hash = ! empty($artist->image_hash)
            ? $artist->image_hash
            : $this->cache->hashFor($artist->name);

        if ($hash === Artist::PLACEHOLDER_HASH) {
            return $this->cache->placeholderBytes();
        }

        $bin = $this->cache->get($hash);
        if ($bin === null) {
            return null;
        }

        if (empty($artist->image_hash)) {
            $artist->update(['image_hash' => $hash]);
        }

        return $bin;
    }

    /**
     * Image contents for an artist, downloading it on a cache miss. A failed
     * download serves the placeholder (null only if the placeholder file is missing).
     */
    public function imageFor(string $artistName, ?string $mbid = null): ?string
    {
        $artist = Artist::where('name', $artistName)->first();
        $hash = $this->cache->hashFor($artistName);

        if ($artist && ! empty($artist->image_hash)) {
            if ($artist->image_hash === Artist::PLACEHOLDER_HASH) {
                if (! $this->placeholderExpired($artist)) {
                    return $this->cache->placeholderBytes();
                }
            } elseif (($bin = $this->cache->get($artist->image_hash)) !== null) {
                return $bin;
            }
        } elseif (($bin = $this->cache->get($hash)) !== null) {
            $artist?->update(['image_hash' => $hash]);

            return $bin;
        }

        $artist ??= Artist::create([
            'name' => $artistName,
            'lastfm_url' => ArtistUrl::for($artistName),
            'musicbrainz_id' => $mbid,
            'image_hash' => null,
        ]);

        $outcome = $this->fetchAndStore($artistName, $hash);

        if ($outcome !== self::STORED) {
            $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);

            return $this->cache->placeholderBytes();
        }

        $this->rememberHash($artist, $hash);

        return $this->cache->get($hash);
    }

    /**
     * Re-download from Last.fm, discarding any cached copy.
     */
    public function regenerate(int $artistId): bool
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return false;
        }

        Log::channel('artist_images')->info('Artist image download triggered', [
            'artistId' => $artistId,
            'artist' => $artist->name,
        ]);

        $hash = $this->cache->hashFor($artist->name);

        if ($artist->image_hash !== Artist::PLACEHOLDER_HASH) {
            $this->cache->forget($hash);
        }

        $outcome = $this->fetchAndStore($artist->name, $hash);

        if ($outcome === self::FAILED) {
            Log::channel('artist_images')->warning('Artist image download failed', [
                'artistId' => $artistId,
                'artist' => $artist->name,
            ]);
            $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);

            return false;
        }

        $isPlaceholder = $outcome === self::PLACEHOLDER;
        $this->rememberHash($artist, $isPlaceholder ? Artist::PLACEHOLDER_HASH : $hash);

        Log::channel('artist_images')->info('Artist image download succeeded', [
            'artistId' => $artistId,
            'artist' => $artist->name,
            'key' => $isPlaceholder ? null : $this->cache->keyFor($hash),
            'isPlaceholder' => $isPlaceholder,
        ]);

        return true;
    }

    /**
     * Download from an operator-supplied URL (admin panel).
     */
    public function fromUrl(int $artistId, string $imageUrl): bool
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return false;
        }

        $hash = $this->cache->hashFor($artist->name);

        if ($artist->image_hash !== Artist::PLACEHOLDER_HASH) {
            $this->cache->forget($hash);
        }

        $bin = $this->scraper->download($imageUrl, $artist->name, 'image-url-download');

        if ($bin === null) {
            Log::channel('artist_images')->warning('Image URL download failed', [
                'artist' => $artist->name,
                'url' => $imageUrl,
            ]);
            $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);

            return false;
        }

        if ($this->cache->isPlaceholder($bin)) {
            $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);
            Log::channel('artist_images')->info('Downloaded image is a placeholder', [
                'artist' => $artist->name,
                'url' => $imageUrl,
            ]);

            return true;
        }

        if (! $this->store($hash, $bin, $artist->name, $imageUrl)) {
            $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);

            return false;
        }

        $this->rememberHash($artist, $hash);

        Log::channel('artist_images')->info('Downloaded image from URL', [
            'artist' => $artist->name,
            'url' => $imageUrl,
        ]);

        return true;
    }

    /**
     * @return self::STORED|self::PLACEHOLDER|self::FAILED
     */
    private function fetchAndStore(string $artistName, string $hash): string
    {
        $imageUrl = $this->scraper->imageUrlFor($artistName);
        if ($imageUrl === null || $imageUrl === '') {
            return self::FAILED;
        }

        $bin = $this->scraper->download($imageUrl, $artistName);
        if ($bin === null) {
            return self::FAILED;
        }

        if ($this->cache->isPlaceholder($bin)) {
            Log::channel('artist_images')->debug('Downloaded image is a placeholder', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return self::PLACEHOLDER;
        }

        return $this->store($hash, $bin, $artistName, $imageUrl) ? self::STORED : self::FAILED;
    }

    private function store(string $hash, string $bin, string $artistName, string $imageUrl): bool
    {
        try {
            $this->cache->put($hash, $bin);

            return true;
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Downloaded image could not be stored', [
                'artist' => $artistName,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
