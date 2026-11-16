<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Models\Album;
use App\Services\LastFm\Concerns\TracksImageHash;
use Illuminate\Support\Facades\Log;

/**
 * Resolves album covers: album.getinfo gives the cover URL (Last.fm still
 * serves album art through the API); when it has none, the og:image of the
 * public album page is used. The binary is downloaded through the same proxy
 * fallback as artist images and cached normalized.
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
     * Cover contents, downloading it on a cache miss. Without a cover the
     * placeholder is served (null only if the placeholder file is missing).
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

        // A recent failure (Last.fm blocking us) is not retried on every chart.
        if ($this->recentlyFailed($hash)) {
            return $this->cache->placeholderBytes();
        }

        $outcome = $this->fetchAndStore($album, $hash);
        $this->applyOutcome($album, $outcome, $hash, Album::PLACEHOLDER_HASH);

        if ($outcome === ImageOutcome::Failed) {
            $this->rememberFailure($hash);
        }

        if ($outcome !== ImageOutcome::Stored) {
            return $this->cache->placeholderBytes();
        }

        $this->forgetFailure($hash);

        return $this->cache->get($hash);
    }

    public function cachedImage(Album $album): ?string
    {
        if ($album->image_hash === Album::PLACEHOLDER_HASH) {
            return $this->cache->placeholderBytes();
        }

        return $this->cache->get($this->cache->hashFor($album->artist_name, $album->name));
    }

    /**
     * Re-download from Last.fm. The current cover is only replaced by a
     * successful download; a failure leaves the album untouched.
     */
    public function regenerate(Album $album): ImageOutcome
    {
        $hash = $this->cache->hashFor($album->artist_name, $album->name);

        $outcome = $this->fetchAndStore($album, $hash);
        $this->applyOutcome($album, $outcome, $hash, Album::PLACEHOLDER_HASH);

        if ($outcome === ImageOutcome::Stored) {
            $this->forgetFailure($hash);
        }

        Log::channel('artist_images')->{$outcome === ImageOutcome::Failed ? 'warning' : 'info'}('Album cover download', [
            'artist' => $album->artist_name,
            'album' => $album->name,
            'outcome' => $outcome->value,
        ]);

        return $outcome;
    }

    public function fromUrl(Album $album, string $imageUrl): bool
    {
        $hash = $this->cache->hashFor($album->artist_name, $album->name);
        $bin = $this->scraper->download($imageUrl, $album->artist_name, 'album-cover-url');

        if ($bin === null || $this->cache->isPlaceholder($bin)) {
            return false;
        }

        try {
            $this->cache->put($hash, $bin);
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Album cover from URL could not be stored', [
                'album' => $album->name,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->rememberHash($album, $hash);
        $this->forgetFailure($hash);

        return true;
    }

    public static function originalSizeUrl(string $url): ?string
    {
        $original = preg_replace('#/i/u/(?:\d+x\d+|\d+s)/#', '/i/u/', $url, 1, $count);

        return $count === 1 ? $original : null;
    }

    private function fetchAndStore(Album $album, string $hash): ImageOutcome
    {
        $context = ['artist' => $album->artist_name, 'album' => $album->name];

        try {
            $imageUrl = $this->api->albumImageUrl($album->artist_name, $album->name, $album->musicbrainz_id);
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Album info request failed', $context + ['error' => $e->getMessage()]);

            return ImageOutcome::Failed;
        }

        if ($imageUrl !== null && $imageUrl !== '' && ! LastFmPlaceholders::matches($imageUrl)) {
            return $this->downloadCover($imageUrl, $album, $hash, $context);
        }

        // The API has no cover (or only the default one): try the album page.
        $lookup = $this->scraper->albumImageUrlFor($album->artist_name, $album->name);
        if (! $lookup->isFound()) {
            return $lookup->status;
        }

        return $this->downloadCover((string) $lookup->url, $album, $hash, $context + ['source' => 'album-page']);
    }

    /**
     * Original size first, then the URL as given.
     *
     * @param  array<string,string>  $context
     */
    private function downloadCover(string $imageUrl, Album $album, string $hash, array $context): ImageOutcome
    {
        $original = self::originalSizeUrl($imageUrl);
        if ($original !== null && $this->downloadAndStore($original, $album, $hash, $context) === ImageOutcome::Stored) {
            return ImageOutcome::Stored;
        }

        return $this->downloadAndStore($imageUrl, $album, $hash, $context);
    }

    /**
     * @param  array<string,string>  $context
     */
    private function downloadAndStore(string $url, Album $album, string $hash, array $context): ImageOutcome
    {
        $bin = $this->scraper->download($url, $album->artist_name, 'album-cover');
        if ($bin === null) {
            return ImageOutcome::Failed;
        }

        if ($this->cache->isPlaceholder($bin)) {
            return ImageOutcome::Placeholder;
        }

        try {
            $this->cache->put($hash, $bin);
        } catch (\RuntimeException $e) {
            Log::channel('artist_images')->warning('Album cover could not be stored', $context + ['url' => $url, 'error' => $e->getMessage()]);

            return ImageOutcome::Failed;
        }

        return ImageOutcome::Stored;
    }
}
