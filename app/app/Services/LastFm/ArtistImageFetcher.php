<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Models\Artist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Resolves artist images, coordinating the scraper, the filesystem cache and
 * the artists.image_hash bookkeeping.
 *
 * image_hash holds either the cache hash, the PLACEHOLDER_HASH sentinel (the
 * download failed or Last.fm served a stub image) or null/'' when nothing has
 * been fetched yet. A placeholder is attempted again once it is older than
 * placeholder_retry_days, the next time the artist shows up in a chart.
 */
final class ArtistImageFetcher
{
    public function __construct(
        private readonly ArtistImageScraper $scraper,
        private readonly ArtistImageCache $cache,
    ) {}

    /**
     * Local cached path, without triggering any download.
     */
    public function cachedPath(Artist $artist): ?string
    {
        $hash = ! empty($artist->image_hash)
            ? $artist->image_hash
            : $this->cache->hashFor($artist->name);

        if ($hash === Artist::PLACEHOLDER_HASH) {
            return is_file($this->cache->placeholderPath()) ? $this->cache->placeholderPath() : null;
        }

        if (! $this->cache->has($hash)) {
            return null;
        }

        if (empty($artist->image_hash)) {
            $artist->update(['image_hash' => $hash]);
        }

        return $this->cache->pathFor($hash);
    }

    /**
     * Cached path for an artist, downloading it on a cache miss. A failed
     * download serves the placeholder ('' only if the placeholder file is missing).
     */
    public function pathFor(string $artistName, ?string $mbid = null): string
    {
        $artist = Artist::where('name', $artistName)->first();
        $hash = $this->cache->hashFor($artistName);

        if ($artist && ! empty($artist->image_hash)) {
            if ($artist->image_hash === Artist::PLACEHOLDER_HASH) {
                if (! $this->placeholderExpired($artist) && is_file($this->cache->placeholderPath())) {
                    return $this->cache->placeholderPath();
                }
            } elseif ($this->cache->has($artist->image_hash)) {
                return $this->cache->pathFor($artist->image_hash);
            }
        } elseif ($this->cache->has($hash)) {
            $artist?->update(['image_hash' => $hash]);

            return $this->cache->pathFor($hash);
        }

        $artist ??= Artist::create([
            'name' => $artistName,
            'lastfm_url' => ArtistUrl::for($artistName),
            'musicbrainz_id' => $mbid,
            'image_hash' => null,
        ]);

        $result = $this->fetchAndStore($artistName, $hash);

        if ($result === '') {
            $this->markPlaceholder($artist);

            return is_file($this->cache->placeholderPath()) ? $this->cache->placeholderPath() : '';
        }

        $this->rememberHash($artist, $result === $this->cache->placeholderPath() ? Artist::PLACEHOLDER_HASH : $hash);

        return $result;
    }

    private function placeholderExpired(Artist $artist): bool
    {
        return $artist->updated_at !== null && $artist->updated_at->lt($this->placeholderCutoff());
    }

    private function placeholderCutoff(): Carbon
    {
        return now()->subDays((int) config('lastfm.images.placeholder_retry_days', 30));
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

        $result = $this->fetchAndStore($artist->name, $hash);

        if ($result === '') {
            Log::channel('artist_images')->warning('Artist image download failed', [
                'artistId' => $artistId,
                'artist' => $artist->name,
            ]);
            $this->markPlaceholder($artist);

            return false;
        }

        $isPlaceholder = $result === $this->cache->placeholderPath();
        $this->rememberHash($artist, $isPlaceholder ? Artist::PLACEHOLDER_HASH : $hash);

        Log::channel('artist_images')->info('Artist image download succeeded', [
            'artistId' => $artistId,
            'artist' => $artist->name,
            'path' => $result,
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
            $this->markPlaceholder($artist);

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

        $this->cache->put($hash, $bin);
        $this->rememberHash($artist, $hash);

        Log::channel('artist_images')->info('Downloaded image from URL', [
            'artist' => $artist->name,
            'url' => $imageUrl,
        ]);

        return true;
    }

    /**
     * Returns the stored path, the placeholder path, or '' on failure.
     */
    private function fetchAndStore(string $artistName, string $hash): string
    {
        $imageUrl = $this->scraper->imageUrlFor($artistName);
        if ($imageUrl === null || $imageUrl === '') {
            return '';
        }

        $bin = $this->scraper->download($imageUrl, $artistName);
        if ($bin === null) {
            return '';
        }

        if ($this->cache->isPlaceholder($bin)) {
            Log::channel('artist_images')->debug('Downloaded image is a placeholder', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return $this->cache->placeholderPath();
        }

        return $this->cache->put($hash, $bin);
    }

    private function markPlaceholder(Artist $artist): void
    {
        $this->rememberHash($artist, Artist::PLACEHOLDER_HASH);
    }

    private function rememberHash(Artist $artist, string $hash): void
    {
        if ($artist->image_hash !== $hash) {
            $artist->update(['image_hash' => $hash]);

            return;
        }

        // Same outcome as last time — refresh the timestamp so a placeholder
        // does not stay permanently expired.
        $artist->touch();
    }
}
