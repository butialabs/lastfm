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
     * Image contents for an artist, downloading it on a cache miss. Without an
     * image the placeholder is served (null only if the placeholder file is
     * missing).
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

        // A recent failure (Last.fm blocking us) is not retried on every chart.
        if ($this->recentlyFailed($hash)) {
            return $this->cache->placeholderBytes();
        }

        $outcome = $this->fetchAndStore($artistName, $hash);
        $this->applyOutcome($artist, $outcome, $hash, Artist::PLACEHOLDER_HASH);

        if ($outcome === ImageOutcome::Failed) {
            $this->rememberFailure($hash);
        }

        if ($outcome !== ImageOutcome::Stored) {
            return $this->cache->placeholderBytes();
        }

        $this->forgetFailure($hash);

        return $this->cache->get($hash);
    }

    /**
     * Re-download from Last.fm. The current image is only replaced by a
     * successful download; a failure leaves the artist untouched.
     */
    public function regenerate(int $artistId): ImageOutcome
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return ImageOutcome::Failed;
        }

        Log::channel('artist_images')->info('Artist image download triggered', [
            'artistId' => $artistId,
            'artist' => $artist->name,
        ]);

        $hash = $this->cache->hashFor($artist->name);
        $outcome = $this->fetchAndStore($artist->name, $hash);
        $this->applyOutcome($artist, $outcome, $hash, Artist::PLACEHOLDER_HASH);

        if ($outcome === ImageOutcome::Stored) {
            $this->forgetFailure($hash);
        }

        Log::channel('artist_images')->{$outcome === ImageOutcome::Failed ? 'warning' : 'info'}('Artist image download finished', [
            'artistId' => $artistId,
            'artist' => $artist->name,
            'outcome' => $outcome->value,
            'key' => $outcome === ImageOutcome::Stored ? $this->cache->keyFor($hash) : null,
        ]);

        return $outcome;
    }

    /**
     * Download from an operator-supplied URL (admin panel). On failure the
     * current image is kept.
     */
    public function fromUrl(int $artistId, string $imageUrl): bool
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return false;
        }

        $hash = $this->cache->hashFor($artist->name);
        $bin = $this->scraper->download($imageUrl, $artist->name, 'image-url-download');

        if ($bin === null || $this->cache->isPlaceholder($bin)) {
            Log::channel('artist_images')->warning('Image URL download failed', [
                'artist' => $artist->name,
                'url' => $imageUrl,
                'reason' => $bin === null ? 'download' : 'too-small',
            ]);

            return false;
        }

        if (! $this->store($hash, $bin, $artist->name, $imageUrl)) {
            return false;
        }

        $this->rememberHash($artist, $hash);
        $this->forgetFailure($hash);

        Log::channel('artist_images')->info('Downloaded image from URL', [
            'artist' => $artist->name,
            'url' => $imageUrl,
        ]);

        return true;
    }

    private function fetchAndStore(string $artistName, string $hash): ImageOutcome
    {
        $lookup = $this->scraper->imageUrlFor($artistName);
        if (! $lookup->isFound()) {
            return $lookup->status;
        }

        $imageUrl = (string) $lookup->url;
        $bin = $this->scraper->download($imageUrl, $artistName);
        if ($bin === null) {
            return ImageOutcome::Failed;
        }

        if ($this->cache->isPlaceholder($bin)) {
            Log::channel('artist_images')->debug('Downloaded image is a placeholder', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return ImageOutcome::Placeholder;
        }

        return $this->store($hash, $bin, $artistName, $imageUrl) ? ImageOutcome::Stored : ImageOutcome::Failed;
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
