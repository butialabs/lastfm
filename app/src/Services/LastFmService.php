<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ArtistRepository;
use GuzzleHttp\Client as Guzzle;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class LastFmService
{
    private const PLACEHOLDER_LASTFM_HASH = '2a96cbd8b46e442fc41c2b86b821562f';

    private const PLACEHOLDER_LASTFM_MD5 = [
        '86de200d58c75a36aa455dd93052ab4e', // ar0, _s, _m, _l (3417 bytes)
        '5fa82c9716bd89010826eb5c31426378', // 300x300 (2086 bytes)
        '16c89c93f617445e8b5a8359cd623261', // 500x500 (3657 bytes)
        '7791e4e2a2f69c6cd9cef72b0e407a29', // 770x0 (6389 bytes)
        'c80d9cdd193cb447895bc9549613ffaa', // 174s (1295 bytes)
        'c5c111dd6fd24f0bf097fbb118353a69', // 64s (762 bytes)
        '940cfccb7ab45df27d0672483371e00d', // 34s (653 bytes)
    ];

    private const PLACEHOLDER_MAX_BYTES = 10000;

    private const PLACEHOLDER_SIMILARITY_THRESHOLD = 5.0;

    public const PLACEHOLDER_HASH = '_placeholder';

    private string $cacheDir;
    private string $basePath;
    private Guzzle $http;
    private LoggerInterface $logger;
    private ArtistRepository $artistRepository;

    public function __construct(
        Guzzle $http,
        LoggerInterface $logger,
        ArtistRepository $artistRepository,
        string $basePath
    ) {
        $this->http = $http;
        $this->logger = $logger;
        $this->artistRepository = $artistRepository;
        $this->basePath = $basePath;
        $this->cacheDir = $basePath . '/data/cache/artists';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Proxy fallback
     */
    private function proxyUrl(): ?string
    {
        $url = trim((string) ($_ENV['LASTFM_PROXY_URL'] ?? ''));
        return $url !== '' ? $url : null;
    }

    /**
     * Standardised GET against Last.fm.
     * If proxy is configured: Proxy (2x attempts), no direct.
     * If proxy is not configured: Direct (1x attempt).
     * Returns the first response satisfying $isSuccess, or null.
     *
     * @param array<string,mixed> $options
     * @param callable(ResponseInterface):bool $isSuccess
     * @param array<string,mixed> $logCtx
     */
    private function getProxy(string $url, array $options, callable $isSuccess, string $context, array $logCtx = []): ?ResponseInterface
    {
        $proxy = $this->proxyUrl();

        $steps = $proxy !== null ? [$proxy, $proxy] : [null];

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
                $res = $this->http->get($url, $opts);
                if ($isSuccess($res)) {
                    return $res;
                }
                $this->logger->debug('Last.fm request non-success', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'status' => $res->getStatusCode(),
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('Last.fm request failed', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Path to the static placeholder image served when Last.fm returns its default image.
     */
    private function placeholderPath(): string
    {
        return $this->basePath . '/public/dist/images/placeholder.jpg';
    }

    /**
     * Check if an og:image URL points to Last.fm's default placeholder.
     */
    private function isPlaceholderImageUrl(string $url): bool
    {
        return str_contains($url, self::PLACEHOLDER_LASTFM_HASH);
    }

    /**
     * Check if a downloaded binary matches Last.fm's default placeholder.
     * Tries known MD5 hashes first, then falls back to GD perceptual
     * comparison for small files with unknown hashes.
     */
    private function isPlaceholderBinary(string $bin): bool
    {
        if (in_array(md5($bin), self::PLACEHOLDER_LASTFM_MD5, true)) {
            return true;
        }

        if (strlen($bin) > self::PLACEHOLDER_MAX_BYTES) {
            return false;
        }

        return $this->binMatchesPlaceholderImage($bin);
    }

    private function isPlaceholderFile(string $path): bool
    {
        $md5 = md5_file($path);
        if ($md5 !== false && in_array($md5, self::PLACEHOLDER_LASTFM_MD5, true)) {
            return true;
        }

        if (filesize($path) > self::PLACEHOLDER_MAX_BYTES) {
            return false;
        }

        $bin = @file_get_contents($path);
        return $bin !== false && $this->binMatchesPlaceholderImage($bin);
    }

    private function binMatchesPlaceholderImage(string $bin): bool
    {
        $placeholderPath = $this->placeholderPath();
        if (!is_file($placeholderPath)) {
            return false;
        }

        $candidate = @imagecreatefromstring($bin);
        if ($candidate === false) {
            return false;
        }

        $reference = @imagecreatefromstring((string) file_get_contents($placeholderPath));
        if ($reference === false) {
            return false;
        }

        $ts = 16;
        $thumbA = imagecreatetruecolor($ts, $ts);
        $thumbB = imagecreatetruecolor($ts, $ts);
        imagecopyresampled($thumbA, $candidate, 0, 0, 0, 0, $ts, $ts, imagesx($candidate), imagesy($candidate));
        imagecopyresampled($thumbB, $reference, 0, 0, 0, 0, $ts, $ts, imagesx($reference), imagesy($reference));

        $diff = 0;
        for ($y = 0; $y < $ts; $y++) {
            for ($x = 0; $x < $ts; $x++) {
                $ca = imagecolorat($thumbA, $x, $y);
                $cb = imagecolorat($thumbB, $x, $y);
                $dr = abs((($ca >> 16) & 0xFF) - (($cb >> 16) & 0xFF));
                $dg = abs((($ca >> 8) & 0xFF) - (($cb >> 8) & 0xFF));
                $db = abs(($ca & 0xFF) - ($cb & 0xFF));
                $diff += ($dr + $dg + $db) / 3;
            }
        }

        $avgDiff = $diff / ($ts * $ts);

        if ($avgDiff < self::PLACEHOLDER_SIMILARITY_THRESHOLD) {
            $this->logger->debug('Image matches placeholder via perceptual comparison', [
                'avgDiff' => round($avgDiff, 2),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Scan the cache directory for files that match Last.fm's default placeholder
     * and replace them with the placeholder hash in the database, deleting the duplicates.
     *
     * @return array{scanned:int, replaced:int}
     */
    public function fixPlaceholderImages(): array
    {
        $placeholderPath = $this->placeholderPath();
        if (!is_file($placeholderPath)) {
            $this->logger->warning('Placeholder file not found, cannot fix', ['path' => $placeholderPath]);
            return ['scanned' => 0, 'replaced' => 0];
        }

        $scanned = 0;
        $replaced = 0;

        $files = glob($this->cacheDir . '/*.jpg') ?: [];
        foreach ($files as $file) {
            if (basename($file) === self::PLACEHOLDER_HASH . '.jpg') {
                continue;
            }

            $scanned++;

            if (!$this->isPlaceholderFile($file)) {
                continue;
            }

            $hash = basename($file, '.jpg');
            $updated = $this->artistRepository->updateImageHash($hash, self::PLACEHOLDER_HASH);

            if ($updated > 0) {
                unlink($file);
                $replaced += $updated;
                $this->logger->info('Replaced placeholder duplicate', [
                    'hash' => $hash,
                    'updated' => $updated,
                ]);
            }
        }

        return ['scanned' => $scanned, 'replaced' => $replaced];
    }

    public function validateUser(string $username): bool
    {
        $data = $this->call('user.getinfo', ['user' => $username]);
        return isset($data['user']['name']);
    }

    /**
     * @return list<array{name:string,playcount:int,imageUrl:?string,mbid:?string}>
     */
    public function getWeeklyArtistChart(string $username, int $limit = 5, ?int $userId = null): array
    {
        $data = $this->call('user.getweeklyartistchart', ['user' => $username]);
        $artists = $data['weeklyartistchart']['artist'] ?? [];
        if (!is_array($artists)) {
            return [];
        }

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $out = [];
        $position = 1;
        foreach ($artists as $a) {
            if (!is_array($a)) {
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

            $artistUrl = $this->buildLastFmArtistUrl($name);

            $artist = $this->artistRepository->findByName($name);

            if (!$artist) {
                $artistId = $this->artistRepository->create([
                    'name' => $name,
                    'lastfm_url' => $artistUrl,
                    'musicbrainz_id' => $mbid !== '' ? $mbid : null,
                    'image_hash' => null,
                ]);
            } else {
                $artistId = (int) $artist['id'];

                if ($mbid !== '' && empty($artist['musicbrainz_id'])) {
                    $this->artistRepository->update($artistId, [
                        'musicbrainz_id' => $mbid
                    ]);
                }
            }

            if ($userId !== null) {
                $this->artistRepository->recordStats($artistId, $userId, $position, $playcount);
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
        if (!is_array($artists)) {
            return 0;
        }

        if (isset($artists['name'])) {
            $artists = [$artists];
        }

        $total = 0;
        foreach ($artists as $a) {
            if (!is_array($a)) {
                continue;
            }
            $playcount = (int) ($a['playcount'] ?? 0);
            $total += $playcount;
        }

        return $total;
    }

    /**
     * Return the local cached image path for an artist without triggering any download.
     */
    public function getCachedImagePath(array $artist): ?string
    {
        $hash = !empty($artist['image_hash'])
            ? $artist['image_hash']
            : md5(strtolower(trim((string) $artist['name'])));

        if ($hash === self::PLACEHOLDER_HASH) {
            $placeholderPath = $this->placeholderPath();
            return is_file($placeholderPath) ? $placeholderPath : null;
        }

        $path = $this->cacheDir . '/' . $hash . '.jpg';

        if (!is_file($path)) {
            return null;
        }

        if (empty($artist['image_hash']) && !empty($artist['id'])) {
            $this->artistRepository->update((int) $artist['id'], ['image_hash' => $hash]);
        }

        return $path;
    }

    /**
     * Force a re-download of the artist image from the default sources
     * from Last.fm, overwriting any cached copy.
     */
    public function regenerateArtistImage(int $artistId): bool
    {
        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return false;
        }

        $this->logger->info('Force Download triggered', [
            'artistId' => $artistId,
            'artist' => $artist['name'],
            'proxyFallback' => $this->proxyUrl() !== null ? 'available' : 'disabled',
        ]);

        $artistHash = md5(strtolower(trim((string) $artist['name'])));
        $path = $this->cacheDir . '/' . $artistHash . '.jpg';

        if ($artist['image_hash'] !== self::PLACEHOLDER_HASH && is_file($path)) {
            unlink($path);
        }

        $result = $this->fetchAndSaveFromLastFm($artist['name'], $path);

        if ($result === '') {
            $this->logger->warning('Force Download failed', [
                'artistId' => $artistId,
                'artist' => $artist['name'],
            ]);
            return false;
        }

        $isPlaceholder = ($result === $this->placeholderPath());
        $hashToStore = $isPlaceholder ? self::PLACEHOLDER_HASH : $artistHash;

        if ($artist['image_hash'] !== $hashToStore) {
            $this->artistRepository->update($artistId, ['image_hash' => $hashToStore]);
        }

        $this->logger->info('Force Download succeeded', [
            'artistId' => $artistId,
            'artist' => $artist['name'],
            'path' => $result,
            'isPlaceholder' => $isPlaceholder,
        ]);

        return true;
    }

    public function getArtistImagePath(string $artistName, ?string $imageUrl = null, ?string $mbid = null): string
    {
        $artist = $this->artistRepository->findByName($artistName);
        $artistHash = md5(strtolower(trim($artistName)));

        if ($artist && !empty($artist['image_hash'])) {
            $hash = $artist['image_hash'];

            if ($hash === self::PLACEHOLDER_HASH) {
                $placeholderPath = $this->placeholderPath();
                if (is_file($placeholderPath)) {
                    return $placeholderPath;
                }
            } else {
                $path = $this->cacheDir . '/' . $hash . '.jpg';
                if (is_file($path)) {
                    return $path;
                }
            }
        } else {
            $path = $this->cacheDir . '/' . $artistHash . '.jpg';

            if (is_file($path)) {
                if ($artist) {
                    $this->artistRepository->update((int)$artist['id'], [
                        'image_hash' => $artistHash
                    ]);
                }
                return $path;
            }
        }

        if (!$artist) {
            $artistUrl = $this->buildLastFmArtistUrl($artistName);

            $artistId = $this->artistRepository->create([
                'name' => $artistName,
                'lastfm_url' => $artistUrl,
                'musicbrainz_id' => $mbid,
                'image_hash' => null,
            ]);

            $artist = ['id' => $artistId, 'image_hash' => null];
        }

        $downloadPath = $this->cacheDir . '/' . $artistHash . '.jpg';
        $result = $this->fetchAndSaveFromLastFm($artistName, $downloadPath);
        if ($result !== '') {
            $isPlaceholder = ($result === $this->placeholderPath());
            $hashToStore = $isPlaceholder ? self::PLACEHOLDER_HASH : $artistHash;

            if (empty($artist['image_hash']) || $artist['image_hash'] !== $hashToStore) {
                $this->artistRepository->update((int)$artist['id'], [
                    'image_hash' => $hashToStore
                ]);
            }
            return $result;
        }

        return '';
    }

    /**
     * Regenerate artist image from a URL
     *
     * @param int $artistId The artist ID
     * @param string $imageUrl The URL of the image to download
     * @return bool Whether the regeneration was successful
     */
    public function downloadArtistImageFromUrl(int $artistId, string $imageUrl): bool
    {
        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return false;
        }

        $artistName = $artist['name'];
        $artistHash = md5(strtolower(trim($artistName)));

        if ($artist['image_hash'] !== self::PLACEHOLDER_HASH) {
            $path = $this->cacheDir . '/' . $artistHash . '.jpg';
            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($this->isPlaceholderImageUrl($imageUrl)) {
            $this->artistRepository->update($artistId, ['image_hash' => self::PLACEHOLDER_HASH]);
            $this->logger->info('Image URL is Last.fm placeholder, using static placeholder', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);
            return true;
        }

        $res = $this->getProxy(
            $imageUrl,
            $this->imageBinaryOptions(),
            static fn(ResponseInterface $r) => $r->getStatusCode() >= 200 && $r->getStatusCode() < 300,
            'image-url-download',
            ['artist' => $artistName, 'url' => $imageUrl]
        );

        if ($res === null) {
            $this->logger->warning('Image URL download failed', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);
            return false;
        }

        $bin = (string) $res->getBody();
        if ($bin === '') {
            return false;
        }

        if ($this->isPlaceholderBinary($bin)) {
            $this->artistRepository->update($artistId, ['image_hash' => self::PLACEHOLDER_HASH]);
            $this->logger->info('Downloaded image matches Last.fm placeholder, using static placeholder', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);
            return true;
        }

        $path = $this->cacheDir . '/' . $artistHash . '.jpg';
        file_put_contents($path, $bin);

        if ($artist['image_hash'] !== $artistHash) {
            $this->artistRepository->update($artistId, ['image_hash' => $artistHash]);
        }

        $this->logger->info('Downloaded image from URL', [
            'artist' => $artistName,
            'url' => $imageUrl
        ]);
        return true;
    }

    /**
     * Fetch and save artist image from Last.fm.
     * Returns the saved file path, the placeholder path, or '' on failure.
     */
    private function fetchAndSaveFromLastFm(string $artistName, string $path): string
    {
        $imageUrl = $this->fetchArtistImageFromLastFmPage($artistName);

        if ($imageUrl === null || $imageUrl === '') {
            return '';
        }

        if ($this->isPlaceholderImageUrl($imageUrl)) {
            $this->logger->debug('Last.fm returned placeholder image URL', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);
            return $this->placeholderPath();
        }

        $bin = $this->downloadImageBinary($imageUrl, $artistName);
        if ($bin === null || $bin === '') {
            return '';
        }

        if ($this->isPlaceholderBinary($bin)) {
            $this->logger->debug('Downloaded image matches Last.fm placeholder binary', [
                'artist' => $artistName,
                'url' => $imageUrl,
            ]);
            return $this->placeholderPath();
        }

        file_put_contents($path, $bin);
        return $path;
    }

    /**
     * Download an image binary, with proxy fallback (Direct 1x -> Proxy 2x).
     */
    private function downloadImageBinary(string $imageUrl, string $artistName): ?string
    {
        $res = $this->getProxy(
            $imageUrl,
            $this->imageBinaryOptions(),
            static fn(ResponseInterface $r) => $r->getStatusCode() >= 200 && $r->getStatusCode() < 300,
            'image-binary',
            ['artist' => $artistName, 'url' => $imageUrl]
        );

        if ($res === null) {
            return null;
        }

        $bin = (string) $res->getBody();
        return $bin !== '' ? $bin : null;
    }

    /**
     * Guzzle options for an image binary download (direct timeouts; proxy bumps them).
     *
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
     * Fetch artist image URL from Last.fm artist page from og:image meta tag
     */
    private function fetchArtistImageFromLastFmPage(string $artistName): ?string
    {
        $url = $this->buildLastFmArtistUrl($artistName);

        $this->logger->debug('Fetching Last.fm artist page', ['url' => $url]);

        // 200 and 404 are both definitive answers; anything else triggers the proxy fallback.
        $res = $this->getProxy(
            $url,
            $this->browserPageOptions($artistName),
            static fn(ResponseInterface $r) => in_array($r->getStatusCode(), [200, 404], true),
            'artist-page',
            ['artist' => $artistName]
        );

        if ($res === null || $res->getStatusCode() === 404) {
            return null;
        }

        return $this->extractOgImage((string) $res->getBody());
    }

    private function extractOgImage(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return null;
        }

        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof \DOMElement) {
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
     * Browser-like Guzzle options for a Last.fm artist page request
     * (direct timeouts; the proxy fallback bumps them).
     *
     * @return array<string,mixed>
     */
    private function browserPageOptions(string $artistName): array
    {
        $referers = [
            'https://www.google.com/',
            'https://www.google.com/search?q=' . rawurlencode($artistName . ' last.fm'),
            'https://www.last.fm/',
            'https://duckduckgo.com/',
            'https://www.bing.com/search?q=' . rawurlencode($artistName),
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

    /**
     * Normalises Unicode to NFC and converts %20 -> '+' to match Last.fm's URL convention
     */
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

        return 'https://www.last.fm/music/' . $slug;
    }

    /**
     * User-Agent from a rotating pool
     */
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

    /** @param mixed $images */
    private function pickLargestImageUrl(mixed $images): ?string
    {
        if (!is_array($images)) {
            return null;
        }
        $best = null;
        foreach ($images as $img) {
            if (!is_array($img)) {
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
        $apiKey = (string) ($_ENV['LASTFM_API'] ?? '');
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
            ['query' => $query, 'http_errors' => false],
            static fn(ResponseInterface $r) => $r->getStatusCode() >= 200 && $r->getStatusCode() < 400,
            'api:' . $method,
            ['method' => $method]
        );

        if ($res === null) {
            throw new \RuntimeException('Last.fm request failed for ' . $method);
        }

        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Last.fm returned invalid JSON');
        }

        if (isset($json['error'])) {
            throw new \RuntimeException('Last.fm error: ' . (string) ($json['message'] ?? 'unknown'));
        }

        return $json;
    }
}
