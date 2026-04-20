<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ArtistRepository;
use App\Services\CurlImpersonateClient;
use GuzzleHttp\Client as Guzzle;
use Psr\Log\LoggerInterface;
use Throwable;

final class LastFmService
{
    private string $cacheDir;
    private Guzzle $http;
    private LoggerInterface $logger;
    private ArtistRepository $artistRepository;
    private CurlImpersonateClient $curlImpersonate;
    private ?array $proxyPool = null;

    public function __construct(
        Guzzle $http,
        LoggerInterface $logger,
        ArtistRepository $artistRepository,
        CurlImpersonateClient $curlImpersonate,
        string $basePath
    ) {
        $this->http = $http;
        $this->logger = $logger;
        $this->artistRepository = $artistRepository;
        $this->curlImpersonate = $curlImpersonate;
        $this->cacheDir = $basePath . '/data/cache/artists';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
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
     * (Last.fm → MusicBrainz), overwriting any cached copy.
     */
    public function regenerateArtistImage(int $artistId): bool
    {
        $artist = $this->artistRepository->findById($artistId);
        if (!$artist) {
            return false;
        }

        $hash = !empty($artist['image_hash'])
            ? $artist['image_hash']
            : md5(strtolower(trim((string) $artist['name'])));
        $path = $this->cacheDir . '/' . $hash . '.jpg';

        if (is_file($path)) {
            unlink($path);
        }

        $result = $this->fetchAndSaveFromLastFm($artist['name'], $path);
        if ($result === '') {
            $imageData = $this->fetchFromMusicBrainz($artist['name'], $artist['musicbrainz_id'] ?? null);
            if ($imageData !== null) {
                file_put_contents($path, $imageData);
                $result = $path;
            }
        }

        if ($result === '' || !is_file($path)) {
            return false;
        }

        if (empty($artist['image_hash'])) {
            $this->artistRepository->update($artistId, ['image_hash' => $hash]);
        }

        return true;
    }

    public function getArtistImagePath(string $artistName, ?string $imageUrl = null, ?string $mbid = null): string
    {
        $artist = $this->artistRepository->findByName($artistName);
        
        if ($artist && !empty($artist['image_hash'])) {
            $hash = $artist['image_hash'];
            $path = $this->cacheDir . '/' . $hash . '.jpg';
            
            if (is_file($path)) {
                return $path;
            }
        } else {
            $hash = md5(strtolower(trim($artistName)));
            $path = $this->cacheDir . '/' . $hash . '.jpg';
            
            if (is_file($path)) {
                if ($artist) {
                    $this->artistRepository->update((int)$artist['id'], [
                        'image_hash' => $hash
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
            
            $artist = ['id' => $artistId];
        }

        // 1. Try Last.fm directly
        $result = $this->fetchAndSaveFromLastFm($artistName, $path);
        if ($result !== '') {
            if (empty($artist['image_hash'])) {
                $this->artistRepository->update((int)$artist['id'], [
                    'image_hash' => $hash
                ]);
            }
            return $result;
        }

        // 2. MusicBrainz
        $imageData = $this->fetchFromMusicBrainz($artistName, $mbid);
        if ($imageData !== null) {
            file_put_contents($path, $imageData);
            
            if (empty($artist['image_hash'])) {
                $this->artistRepository->update((int)$artist['id'], [
                    'image_hash' => $hash
                ]);
            }
            return $path;
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
        $hash = $artist['image_hash'] ?? md5(strtolower(trim($artistName)));
        $path = $this->cacheDir . '/' . $hash . '.jpg';
        
        if (is_file($path)) {
            unlink($path);
        }
        
        try {
            $res = $this->http->get($imageUrl, [
                'headers' => ['Accept' => 'image/*'],
                'timeout' => 15,
                'connect_timeout' => 5,
            ]);
            
            $code = $res->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $bin = (string) $res->getBody();
                if ($bin !== '') {
                    file_put_contents($path, $bin);
                    
                    if (empty($artist['image_hash'])) {
                        $this->artistRepository->update($artistId, [
                            'image_hash' => $hash
                        ]);
                    }
                    
                    $this->logger->info('Downloaded image from URL', [
                        'artist' => $artistName,
                        'url' => $imageUrl
                    ]);
                    return true;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Image URL download failed', [
                'artist' => $artistName,
                'url' => $imageUrl,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Fetch and save artist image from Last.fm
     */
    private function fetchAndSaveFromLastFm(string $artistName, string $path): string
    {
        $imageUrl = $this->fetchArtistImageFromLastFmPage($artistName);

        if ($imageUrl === null || $imageUrl === '') {
            return '';
        }

        try {
            $res = $this->http->get($imageUrl, [
                'headers' => ['Accept' => 'image/*'],
            ]);
            $code = $res->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $bin = (string) $res->getBody();
                if ($bin !== '') {
                    file_put_contents($path, $bin);
                    return $path;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Artist image download failed', ['artist' => $artistName, 'error' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * Fetch artist image URL from Last.fm artist page from og:image meta tag
     */
    private function fetchArtistImageFromLastFmPage(string $artistName): ?string
    {
        $url = $this->buildLastFmArtistUrl($artistName);

        $this->logger->debug('Fetching Last.fm artist page', ['url' => $url]);

        $direct = $this->tryFetchArtistPage($url, $artistName, null, 1);
        if ($direct['definitive']) {
            return $direct['imageUrl'];
        }

        if ($this->curlImpersonate->isAvailable()) {
            $impersonated = $this->tryFetchArtistPageImpersonated($url, $artistName);
            if ($impersonated['definitive']) {
                return $impersonated['imageUrl'];
            }
        }

        $proxies = $this->loadProxyPool();
        if ($proxies === []) {
            $this->logger->warning('Last.fm artist page failed, no proxy configured', [
                'artist' => $artistName,
                'lastStatus' => $direct['status'],
            ]);
            return null;
        }

        $proxy = $proxies[array_rand($proxies)];
        $this->logger->debug('Falling back to proxy', [
            'artist' => $artistName,
            'proxiesAvailable' => count($proxies),
        ]);

        $proxied = $this->tryFetchArtistPage($url, $artistName, $proxy, 1);
        if ($proxied['definitive']) {
            return $proxied['imageUrl'];
        }

        $this->logger->warning('Last.fm artist page failed after direct + proxy', [
            'artist' => $artistName,
            'directStatus' => $direct['status'],
            'proxyStatus' => $proxied['status'],
        ]);
        return null;
    }

    /**
     * Fetch the Last.fm artist page via curl-impersonate
     */
    private function tryFetchArtistPageImpersonated(string $url, string $artistName): array
    {
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,pt-BR;q=0.8,pt;q=0.7',
            'Referer' => 'https://www.google.com/search?q=' . rawurlencode($artistName . ' last.fm'),
            'Upgrade-Insecure-Requests' => '1',
        ];

        $result = $this->curlImpersonate->get($url, $headers, 25, 10);
        if ($result === null) {
            $this->logger->debug('curl-impersonate: fetch failed', ['artist' => $artistName]);
            return ['definitive' => false, 'imageUrl' => null, 'status' => 0];
        }

        $status = $result['status'];

        if ($status === 200) {
            $imageUrl = $this->extractOgImage($result['body']);
            if ($imageUrl !== null) {
                $this->logger->info('Found og:image via curl-impersonate', [
                    'artist' => $artistName,
                    'imageUrl' => $imageUrl,
                ]);
                return ['definitive' => true, 'imageUrl' => $imageUrl, 'status' => $status];
            }
            $this->logger->debug('No og:image via curl-impersonate', ['artist' => $artistName]);
            return ['definitive' => true, 'imageUrl' => null, 'status' => $status];
        }

        if ($status === 404) {
            $this->logger->debug('Last.fm 404 via curl-impersonate', ['artist' => $artistName]);
            return ['definitive' => true, 'imageUrl' => null, 'status' => $status];
        }

        $this->logger->debug('curl-impersonate: non-success status', [
            'artist' => $artistName,
            'status' => $status,
        ]);
        return ['definitive' => false, 'imageUrl' => null, 'status' => $status];
    }

    private function extractOgImage(string $html): ?string
    {
        if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Single attempt at fetching the Last.fm artist page.
     */
    private function tryFetchArtistPage(string $url, string $artistName, ?string $proxy, int $attempt): array
    {
        $referers = [
            'https://www.google.com/',
            'https://www.google.com/search?q=' . rawurlencode($artistName . ' last.fm'),
            'https://www.last.fm/',
            'https://duckduckgo.com/',
            'https://www.bing.com/search?q=' . rawurlencode($artistName),
        ];

        $options = [
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
            'timeout' => $proxy !== null ? 25 : 15,
            'connect_timeout' => $proxy !== null ? 10 : 5,
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
            ],
            'decode_content' => true,
            'http_errors' => false,
            'verify' => true,
        ];

        if ($proxy !== null) {
            $options['proxy'] = $proxy;
        }

        $via = $proxy !== null ? 'proxy' : 'direct';

        try {
            $res = $this->http->get($url, $options);
            $status = $res->getStatusCode();

            if ($status === 200) {
                $imageUrl = $this->extractOgImage((string) $res->getBody());
                if ($imageUrl !== null) {
                    $this->logger->info('Found og:image on Last.fm artist page', [
                        'artist' => $artistName,
                        'imageUrl' => $imageUrl,
                        'via' => $via,
                        'attempt' => $attempt,
                    ]);
                    return ['definitive' => true, 'imageUrl' => $imageUrl, 'status' => $status];
                }

                $this->logger->debug('No og:image found on Last.fm artist page', [
                    'artist' => $artistName,
                    'via' => $via,
                ]);
                return ['definitive' => true, 'imageUrl' => null, 'status' => $status];
            }

            if ($status === 404) {
                $this->logger->debug('Last.fm artist page not found', [
                    'artist' => $artistName,
                    'via' => $via,
                ]);
                return ['definitive' => true, 'imageUrl' => null, 'status' => $status];
            }

            $this->logger->debug('Last.fm artist page non-200', [
                'artist' => $artistName,
                'status' => $status,
                'via' => $via,
                'attempt' => $attempt,
            ]);
            return ['definitive' => false, 'imageUrl' => null, 'status' => $status];

        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch Last.fm artist page', [
                'artist' => $artistName,
                'via' => $via,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);
            return ['definitive' => false, 'imageUrl' => null, 'status' => 0];
        }
    }

    /**
     * Load formatted proxy URLs from env
     */
    private function loadProxyPool(): array
    {
        if ($this->proxyPool !== null) {
            return $this->proxyPool;
        }

        $protocol = strtolower(trim((string) ($_ENV['LASTFM_PROXY_PROTOCOL'] ?? 'http'))) ?: 'http';
        $proxies = [];

        $inline = trim((string) ($_ENV['LASTFM_PROXY_LIST'] ?? ''));
        if ($inline !== '') {
            $lines = preg_split('/[\r\n,]+/', $inline) ?: [];
            foreach ($lines as $line) {
                $formatted = $this->formatProxy(trim($line), $protocol);
                if ($formatted !== null) {
                    $proxies[] = $formatted;
                }
            }
        }

        $listUrl = trim((string) ($_ENV['LASTFM_PROXY_LIST_URL'] ?? ''));
        if ($listUrl !== '') {
            $remoteList = $this->fetchProxyListFromUrl($listUrl);
            foreach ($remoteList as $line) {
                $formatted = $this->formatProxy($line, $protocol);
                if ($formatted !== null) {
                    $proxies[] = $formatted;
                }
            }
        }

        $proxies = array_values(array_unique($proxies));
        $this->proxyPool = $proxies;

        return $proxies;
    }

    /**
     * Convert a proxy URI
     */
    private function formatProxy(string $line, string $protocol): ?string
    {
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (preg_match('#^[a-z0-9]+://#i', $line)) {
            return $line;
        }

        $parts = explode(':', $line);
        $count = count($parts);

        if ($count === 2) {
            [$host, $port] = $parts;
            if ($host === '' || !ctype_digit($port)) {
                return null;
            }
            return $protocol . '://' . $host . ':' . $port;
        }

        if ($count === 4) {
            [$host, $port, $user, $pass] = $parts;
            if ($host === '' || !ctype_digit($port)) {
                return null;
            }
            return $protocol . '://' . rawurlencode($user) . ':' . rawurlencode($pass) . '@' . $host . ':' . $port;
        }

        return null;
    }

    /**
     * Fetch a remote proxy list with on-disk caching
     */
    private function fetchProxyListFromUrl(string $url): array
    {
        $ttl = (int) ($_ENV['LASTFM_PROXY_LIST_TTL'] ?? 86400);
        if ($ttl <= 0) {
            $ttl = 86400;
        }

        $cacheFile = $this->cacheDir . '/../proxies-' . md5($url) . '.txt';
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
            $contents = (string) @file_get_contents($cacheFile);
            return $this->splitProxyListBody($contents);
        }

        try {
            $res = $this->http->get($url, [
                'timeout' => 20,
                'connect_timeout' => 5,
                'http_errors' => false,
                'headers' => ['Accept' => 'text/plain, */*'],
            ]);

            if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                $body = (string) $res->getBody();
                @file_put_contents($cacheFile, $body);
                $this->logger->info('Refreshed proxy list from URL', [
                    'url' => $url,
                    'bytes' => strlen($body),
                ]);
                return $this->splitProxyListBody($body);
            }

            $this->logger->warning('Proxy list URL returned non-2xx', [
                'url' => $url,
                'status' => $res->getStatusCode(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch proxy list URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        if (is_file($cacheFile)) {
            $this->logger->debug('Falling back to stale proxy list cache', ['url' => $url]);
            $contents = (string) @file_get_contents($cacheFile);
            return $this->splitProxyListBody($contents);
        }

        return [];
    }

    private function splitProxyListBody(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }
        return $out;
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

    /**
     * Fetch artist image from MusicBrainz/Cover Art Archive.
     */
    private function fetchFromMusicBrainz(string $artistName, ?string $mbid): ?string
    {
        $apiBase = 'https://musicbrainz.org/ws/2';
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? 'https://lastfm.blue'));
        $userAgent = 'LastFM.blue/1.0 ( ' . $appUrl . ' )';
        
        $httpOptions = [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ],
            'timeout' => 10,
            'connect_timeout' => 5,
        ];
        
        try {
            if ($mbid === null || $mbid === '') {
                $searchUrl = $apiBase . '/artist/?query=' . urlencode('artist:' . $artistName) . '&type=artist&fmt=json&limit=5';
                $this->logger->debug('MusicBrainz artist search', ['url' => $searchUrl]);
                
                $res = $this->http->get($searchUrl, $httpOptions);
                
                if ($res->getStatusCode() !== 200) {
                    return null;
                }
                
                $data = json_decode((string) $res->getBody(), true);
                $artists = $data['artists'] ?? [];
                
                if (empty($artists)) {
                    $this->logger->debug('MusicBrainz: artist not found', ['artist' => $artistName]);
                    return null;
                }
                
                $searchName = $this->normalizeArtistName($artistName);
                $mbid = null;
                foreach ($artists as $candidate) {
                    $candidateName = $this->normalizeArtistName($candidate['name'] ?? '');
                    if ($candidateName === $searchName) {
                        $mbid = $candidate['id'];
                        $this->logger->debug('MusicBrainz: name match found', [
                            'artist' => $artistName,
                            'matched' => $candidate['name'],
                            'mbid' => $mbid,
                        ]);
                        break;
                    }
                }
                
                if ($mbid === null) {
                    $this->logger->debug('MusicBrainz: no name match in results', [
                        'artist' => $artistName,
                        'results' => array_column($artists, 'name'),
                    ]);
                    return null;
                }
                
                usleep(1100000);
            }

            $releasesUrl = $apiBase . '/release/?artist=' . urlencode($mbid) . '&fmt=json&limit=1';
            $this->logger->debug('MusicBrainz releases lookup', ['mbid' => $mbid]);
            
            $res = $this->http->get($releasesUrl, $httpOptions);
            
            if ($res->getStatusCode() !== 200) {
                return null;
            }
            
            $data = json_decode((string) $res->getBody(), true);
            $releaseId = $data['releases'][0]['id'] ?? null;
            
            if ($releaseId === null) {
                $this->logger->debug('MusicBrainz: no releases found', ['artist' => $artistName]);
                return null;
            }

            $coverUrl = 'https://coverartarchive.org/release/' . $releaseId . '/front-500';
            $this->logger->debug('Fetching cover art', ['url' => $coverUrl]);
            
            $res = $this->http->get($coverUrl, [
                'headers' => [
                    'Accept' => 'image/*',
                    'User-Agent' => $userAgent,
                ],
                'timeout' => 15,
                'connect_timeout' => 5,
                'allow_redirects' => true,
            ]);
            
            if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                $imageData = (string) $res->getBody();
                if ($imageData !== '') {
                    $this->logger->info('MusicBrainz: cover art fetched', ['artist' => $artistName]);
                    return $imageData;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('MusicBrainz fetch failed', ['artist' => $artistName, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Normalize artist name for comparison (lowercase, normalize Unicode hyphens).
     */
    private function normalizeArtistName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(
            ["\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xEF\xBC\x8D"],
            '-',
            $name,
        );
        return $name;
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

        $maxRetries = (int) ($_ENV['LASTFM_MAX_RETRIES'] ?? 3);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $res = $this->http->get('https://ws.audioscrobbler.com/2.0/', [
                    'query' => $query,
                ]);
                $code = $res->getStatusCode();

                $json = json_decode((string) $res->getBody(), true);
                if (!is_array($json)) {
                    throw new \RuntimeException('Last.fm returned invalid JSON');
                }

                if ($code >= 400 || isset($json['error'])) {
                    $msg = (string) ($json['message'] ?? ('HTTP ' . $code));
                    throw new \RuntimeException('Last.fm error: ' . $msg);
                }

                return $json;
            } catch (Throwable $e) {
                $lastException = $e;
                $this->logger->warning('Last.fm request failed', [
                    'method' => $method,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxRetries) {
                    $delay = (int) pow(2, $attempt - 1) * 1000000;
                    usleep($delay);
                }
            }
        }

        throw new \RuntimeException('Last.fm request failed after ' . $maxRetries . ' attempts: ' . ($lastException?->getMessage() ?? 'Unknown error'));
    }
}
