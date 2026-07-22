<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Artist;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class LastFmService
{
    // Any image smaller than 2KB is treated as a placeholder.
    private const PLACEHOLDER_MAX_BYTES = 2048;

    public const PLACEHOLDER_HASH = Artist::PLACEHOLDER_HASH;

    private const APP_USER_AGENT = 'LastFM.butialabs.com/1.0';

    /**
     * GET with proxy fallback: Proxy (2x) → Direct (1x).
     * Returns the first response satisfying $isSuccess, or null.
     *
     * @param  array<string,mixed>  $options
     * @param  callable(Response):bool  $isSuccess
     * @param  array<string,mixed>  $logCtx
     */
    private function getProxy(string $url, array $options, callable $isSuccess, string $context, array $logCtx = []): ?Response
    {
        $proxy = $this->proxyUrl();

        $steps = [];
        if ($proxy !== null) {
            $steps[] = $proxy;
            $steps[] = $proxy;
        }
        $steps[] = null;

        foreach ($steps as $p) {
            $opts = $options;
            $via = 'direct';
            if ($p !== null) {
                $via = 'proxy';
                $opts['proxy'] = $p;
                $opts['timeout'] = 25;
                $opts['connect_timeout'] = 10;
                $opts['curl'] = ($opts['curl'] ?? []) + [
                    CURLOPT_PROXY_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY_SSL_VERIFYHOST => 0,
                ];
            }

            try {
                $res = Http::withOptions($opts)->get($url);
                if ($isSuccess($res)) {
                    Log::channel('app')->debug('Last.fm request succeeded', $logCtx + [
                        'context' => $context,
                        'via' => $via,
                        'status' => $res->status(),
                    ]);

                    return $res;
                }
                Log::channel('app')->debug('Last.fm request non-success', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'status' => $res->status(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('app')->warning('Last.fm request failed', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function proxyUrl(): ?string
    {
        $url = trim((string) config('lastfm.proxy_url', ''));

        return $url !== '' ? $url : null;
    }

    private function cacheDir(): string
    {
        $dir = Storage::disk('artist-cache')->path('');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    private function placeholderPath(): string
    {
        return public_path('images/placeholder.jpg');
    }

    private function isPlaceholderBinary(string $bin): bool
    {
        return strlen($bin) < self::PLACEHOLDER_MAX_BYTES;
    }

    public function validateUser(string $username): bool
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
     * @return list<array{name:string,playcount:int,imageUrl:?string,mbid:?string}>
     */
    public function getWeeklyArtistChart(string $username, int $limit = 5, ?int $userId = null): array
    {
        $data = $this->call('user.getweeklyartistchart', ['user' => $username]);
        $artists = $data['weeklyartistchart']['artist'] ?? [];
        if (! is_array($artists)) {
            return [];
        }

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $out = [];
        $position = 1;
        foreach ($artists as $a) {
            if (! is_array($a)) {
                continue;
            }
            $name = (string) ($a['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $playcount = (int) ($a['playcount'] ?? 0);
            $mbid = (string) ($a['mbid'] ?? '');

            $imageUrl = null;
            if (isset($a['image']) && is_array($a['image'])) {
                $imageUrl = $this->pickLargestImageUrl($a['image']);
            }

            $artist = Artist::where('name', $name)->first();

            if (! $artist) {
                $artist = Artist::create([
                    'name' => $name,
                    'lastfm_url' => $this->buildLastFmArtistUrl($name),
                    'musicbrainz_id' => $mbid !== '' ? $mbid : null,
                    'image_hash' => null,
                ]);
            } elseif ($mbid !== '' && empty($artist->musicbrainz_id)) {
                $artist->update(['musicbrainz_id' => $mbid]);
            }

            if ($userId !== null) {
                $artist->recordStats($userId, $position, $playcount);
            }

            $out[] = [
                'name' => $name,
                'playcount' => $playcount,
                'imageUrl' => $imageUrl,
                'mbid' => $mbid !== '' ? $mbid : null,
            ];

            $position++;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function getWeeklyTotalScrobbles(string $username): int
    {
        $data = $this->call('user.getweeklyartistchart', ['user' => $username]);
        $artists = $data['weeklyartistchart']['artist'] ?? [];
        if (! is_array($artists)) {
            return 0;
        }

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $total = 0;
        foreach ($artists as $a) {
            if (is_array($a)) {
                $total += (int) ($a['playcount'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Local cached image path, without triggering any download.
     */
    public function getCachedImagePath(Artist $artist): ?string
    {
        $hash = ! empty($artist->image_hash)
            ? $artist->image_hash
            : md5(strtolower(trim($artist->name)));

        if ($hash === self::PLACEHOLDER_HASH) {
            $placeholderPath = $this->placeholderPath();

            return is_file($placeholderPath) ? $placeholderPath : null;
        }

        $path = $this->cacheDir().'/'.$hash.'.jpg';

        if (! is_file($path)) {
            return null;
        }

        if (empty($artist->image_hash)) {
            $artist->update(['image_hash' => $hash]);
        }

        return $path;
    }

    /**
     * Re-download the artist image from Last.fm, overwriting any cached copy.
     */
    public function regenerateArtistImage(int $artistId): bool
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return false;
        }

        Log::channel('app')->info('Force Download triggered', [
            'artistId' => $artistId,
            'artist' => $artist->name,
            'proxyFallback' => $this->proxyUrl() !== null ? 'available' : 'disabled',
        ]);

        $artistHash = md5(strtolower(trim($artist->name)));
        $path = $this->cacheDir().'/'.$artistHash.'.jpg';

        if ($artist->image_hash !== self::PLACEHOLDER_HASH && is_file($path)) {
            unlink($path);
        }

        $result = $this->fetchAndSaveFromLastFm($artist->name, $path);

        if ($result === '') {
            Log::channel('app')->warning('Force Download failed', [
                'artistId' => $artistId,
                'artist' => $artist->name,
            ]);

            return false;
        }

        $isPlaceholder = ($result === $this->placeholderPath());
        $hashToStore = $isPlaceholder ? self::PLACEHOLDER_HASH : $artistHash;

        if ($artist->image_hash !== $hashToStore) {
            $artist->update(['image_hash' => $hashToStore]);
        }

        Log::channel('app')->info('Force Download succeeded', [
            'artistId' => $artistId,
            'artist' => $artist->name,
            'path' => $result,
            'isPlaceholder' => $isPlaceholder,
        ]);

        return true;
    }

    public function getArtistImagePath(string $artistName, ?string $imageUrl = null, ?string $mbid = null): string
    {
        $artist = Artist::where('name', $artistName)->first();
        $artistHash = md5(strtolower(trim($artistName)));

        if ($artist && ! empty($artist->image_hash)) {
            $hash = $artist->image_hash;

            if ($hash === self::PLACEHOLDER_HASH) {
                $placeholderPath = $this->placeholderPath();
                if (is_file($placeholderPath)) {
                    return $placeholderPath;
                }
            } else {
                $path = $this->cacheDir().'/'.$hash.'.jpg';
                if (is_file($path)) {
                    return $path;
                }
            }
        } else {
            $path = $this->cacheDir().'/'.$artistHash.'.jpg';

            if (is_file($path)) {
                if ($artist) {
                    $artist->update(['image_hash' => $artistHash]);
                }

                return $path;
            }
        }

        if (! $artist) {
            $artist = Artist::create([
                'name' => $artistName,
                'lastfm_url' => $this->buildLastFmArtistUrl($artistName),
                'musicbrainz_id' => $mbid,
                'image_hash' => null,
            ]);
        }

        $downloadPath = $this->cacheDir().'/'.$artistHash.'.jpg';
        $result = $this->fetchAndSaveFromLastFm($artistName, $downloadPath);
        if ($result !== '') {
            $isPlaceholder = ($result === $this->placeholderPath());
            $hashToStore = $isPlaceholder ? self::PLACEHOLDER_HASH : $artistHash;

            if ($artist->image_hash !== $hashToStore) {
                $artist->update(['image_hash' => $hashToStore]);
            }

            return $result;
        }

        return '';
    }

    /**
     * Download the artist image from a given URL.
     */
    public function downloadArtistImageFromUrl(int $artistId, string $imageUrl): bool
    {
        $artist = Artist::find($artistId);
        if (! $artist) {
            return false;
        }

        $artistName = $artist->name;
        $artistHash = md5(strtolower(trim($artistName)));

        if ($artist->image_hash !== self::PLACEHOLDER_HASH) {
            $path = $this->cacheDir().'/'.$artistHash.'.jpg';
            if (is_file($path)) {
                unlink($path);
            }
        }

        $res = $this->getProxy(
            $imageUrl,
            $this->imageBinaryOptions(),
            static fn (Response $r) => $r->status() >= 200 && $r->status() < 300,
            'image-url-download',
            ['artist' => $artistName, 'url' => $imageUrl]
        );

        if ($res === null) {
            Log::channel('app')->warning('Image URL download failed', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return false;
        }

        $bin = $res->body();
        if ($bin === '') {
            return false;
        }

        if ($this->isPlaceholderBinary($bin)) {
            $artist->update(['image_hash' => self::PLACEHOLDER_HASH]);
            Log::channel('app')->info('Downloaded image is placeholder (< 2KB)', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return true;
        }

        $path = $this->cacheDir().'/'.$artistHash.'.jpg';
        file_put_contents($path, $bin);

        if ($artist->image_hash !== $artistHash) {
            $artist->update(['image_hash' => $artistHash]);
        }

        Log::channel('app')->info('Downloaded image from URL', [
            'artist' => $artistName,
            'url' => $imageUrl,
        ]);

        return true;
    }

    /**
     * Fetch and save the artist image from Last.fm.
     * Returns the saved path, the placeholder path, or '' on failure.
     */
    private function fetchAndSaveFromLastFm(string $artistName, string $path): string
    {
        $imageUrl = $this->fetchArtistImageFromLastFmPage($artistName);

        if ($imageUrl === null || $imageUrl === '') {
            return '';
        }

        $bin = $this->downloadImageBinary($imageUrl, $artistName);
        if ($bin === null || $bin === '') {
            return '';
        }

        if ($this->isPlaceholderBinary($bin)) {
            Log::channel('app')->debug('Downloaded image is placeholder (< 2KB)', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);

            return $this->placeholderPath();
        }

        file_put_contents($path, $bin);

        return $path;
    }

    private function downloadImageBinary(string $imageUrl, string $artistName): ?string
    {
        $res = $this->getProxy(
            $imageUrl,
            $this->imageBinaryOptions(),
            static fn (Response $r) => $r->status() >= 200 && $r->status() < 300,
            'image-binary',
            ['artist' => $artistName, 'url' => $imageUrl]
        );

        if ($res === null) {
            return null;
        }

        $bin = $res->body();

        return $bin !== '' ? $bin : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function imageBinaryOptions(): array
    {
        return [
            'headers' => [
                'User-Agent' => $this->pickBrowserUserAgent(),
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://www.last.fm/',
            ],
            'timeout' => 15,
            'connect_timeout' => 5,
            'http_errors' => false,
            'verify' => false,
        ];
    }

    /**
     * Extract the artist image URL from the og:image meta tag of the Last.fm page.
     */
    private function fetchArtistImageFromLastFmPage(string $artistName): ?string
    {
        $url = $this->buildLastFmArtistUrl($artistName);

        Log::channel('app')->debug('Fetching Last.fm artist page', ['url' => $url]);

        // 200 and 404 are definitive answers; anything else triggers the proxy fallback.
        $res = $this->getProxy(
            $url,
            $this->browserPageOptions($artistName),
            static fn (Response $r) => in_array($r->status(), [200, 404], true),
            'artist-page',
            ['artist' => $artistName]
        );

        if ($res === null || $res->status() === 404) {
            return null;
        }

        return $this->extractOgImage($res->body());
    }

    private function extractOgImage(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return null;
        }

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof \DOMElement) {
                continue;
            }
            $property = strtolower($meta->getAttribute('property'));
            if ($property === 'og:image' || $property === 'og:image:secure_url' || $property === 'og:image:url') {
                $content = trim($meta->getAttribute('content'));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function browserPageOptions(string $artistName): array
    {
        $referers = [
            'https://www.google.com/',
            'https://www.google.com/search?q='.rawurlencode($artistName.' last.fm'),
            'https://www.last.fm/',
            'https://duckduckgo.com/',
            'https://www.bing.com/search?q='.rawurlencode($artistName),
        ];

        return [
            'headers' => [
                'User-Agent' => $this->pickBrowserUserAgent(),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Cache-Control' => 'max-age=0',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'cross-site',
                'Sec-Fetch-User' => '?1',
                'Sec-Ch-Ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
                'Sec-Ch-Ua-Mobile' => '?0',
                'Sec-Ch-Ua-Platform' => '"Windows"',
                'Referer' => $referers[array_rand($referers)],
                'DNT' => '1',
                'Connection' => 'keep-alive',
            ],
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
            ],
            'decode_content' => true,
            'http_errors' => false,
            'verify' => false,
        ];
    }

    // NFC-normalizes Unicode and converts %20 → '+' (Last.fm URL convention).
    private function buildLastFmArtistUrl(string $artistName): string
    {
        $name = trim($artistName);

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $name = $normalized;
            }
        }

        $slug = str_replace('%20', '+', rawurlencode($name));

        return 'https://www.last.fm/music/'.$slug;
    }

    private function pickBrowserUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.3; rv:124.0) Gecko/20100101 Firefox/124.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
        ];

        return $agents[array_rand($agents)];
    }

    private function pickLargestImageUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }
        $best = null;
        foreach ($images as $img) {
            if (! is_array($img)) {
                continue;
            }
            $u = (string) ($img['#text'] ?? '');
            if ($u !== '') {
                $best = $u;
            }
        }

        return $best;
    }

    /** @return array<string,mixed> */
    private function call(string $method, array $params): array
    {
        $apiKey = (string) config('lastfm.api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('LASTFM_API is not configured');
        }

        $query = array_merge($params, [
            'method' => $method,
            'api_key' => $apiKey,
            'format' => 'json',
        ]);

        $res = $this->getProxy(
            'https://ws.audioscrobbler.com/2.0/',
            [
                'query' => $query,
                'headers' => ['User-Agent' => self::APP_USER_AGENT],
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

        if (isset($json['error'])) {
            throw new \RuntimeException('Last.fm error: '.(string) ($json['message'] ?? 'unknown'));
        }

        return $json;
    }
}
