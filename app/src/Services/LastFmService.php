<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ProxyService;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Throwable;

final class LastFmService
{
    private string $cacheDir;

    public function __construct(
        private readonly Guzzle $http,
        private readonly LoggerInterface $logger,
        string $basePath,
        private readonly ProxyService $proxyService,
    ) {
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
    public function getWeeklyArtistChart(string $username, int $limit = 5): array
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

            $out[] = [
                'name' => $name,
                'playcount' => $playcount,
                'imageUrl' => $imageUrl,
                'mbid' => $mbid !== '' ? $mbid : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function getArtistImagePath(string $artistName, ?string $imageUrl = null, ?string $mbid = null): string
    {
        $hash = md5(strtolower(trim($artistName)));
        $path = $this->cacheDir . '/' . $hash . '.jpg';
        if (is_file($path)) {
            return $path;
        }

        $musicBrainzDefault = strtolower(trim((string) ($_ENV['MUSICBRAINZ_DEFAULT'] ?? 'false')));
        $useMusicBrainzFirst = in_array($musicBrainzDefault, ['true', '1', 'yes'], true);

        if ($useMusicBrainzFirst) {
            $imageData = $this->fetchFromMusicBrainz($artistName, $mbid);
            if ($imageData !== null) {
                file_put_contents($path, $imageData);
                return $path;
            }

            $result = $this->fetchAndSaveFromLastFm($artistName, $path);
            if ($result !== '') {
                return $result;
            }
        } else {
            $result = $this->fetchAndSaveFromLastFm($artistName, $path);
            if ($result !== '') {
                return $result;
            }

            $imageData = $this->fetchFromMusicBrainz($artistName, $mbid);
            if ($imageData !== null) {
                file_put_contents($path, $imageData);
                return $path;
            }
        }

        return '';
    }

    /**
     * Fetch and save artist image from Last.fm.
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
     * Fetch artist image URL from Last.fm artist page by scraping the og:image meta tag.
     */
    private function fetchArtistImageFromLastFmPage(string $artistName): ?string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? 'https://lastfm.blue'));
        $userAgent = 'LastFM.blue/1.0 ( ' . $appUrl . ' )';
        
        $artistSlug = rawurlencode($artistName);
        $url = 'https://www.last.fm/music/' . $artistSlug;
        
        $this->logger->debug('Fetching Last.fm artist page', ['url' => $url]);
        
        $maxRetries = 10;
        $usedProxies = [];
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Get a random proxy that hasn't been used yet
            $proxy = $this->proxyService->getRandomProxy($usedProxies);
            
            // If no proxy available (or all proxies used), try without proxy on first attempt only
            if ($proxy === null && $attempt > 1) {
                $this->logger->debug('No more proxies available, skipping retry');
                break;
            }
            
            $options = [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => $userAgent,
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ];
            
            if ($proxy !== null) {
                $options['proxy'] = $proxy;
                $usedProxies[] = $proxy;
                $this->logger->debug('Using proxy for Last.fm page fetch', [
                    'proxy' => $proxy,
                    'attempt' => $attempt
                ]);
            }
            
            try {
                $res = $this->http->get($url, $options);
                
                if ($res->getStatusCode() !== 200) {
                    $this->logger->debug('Last.fm artist page returned non-200', [
                        'artist' => $artistName,
                        'status' => $res->getStatusCode()
                    ]);
                    return null;
                }
                
                $html = (string) $res->getBody();
                
                // Extract og:image (existing regex logic)
                if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                    $imageUrl = $matches[1];
                    $this->logger->info('Found og:image on Last.fm artist page', [
                        'artist' => $artistName,
                        'imageUrl' => $imageUrl
                    ]);
                    return $imageUrl;
                }
                
                if (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/i', $html, $matches)) {
                    $imageUrl = $matches[1];
                    $this->logger->info('Found og:image on Last.fm artist page (alt)', [
                        'artist' => $artistName,
                        'imageUrl' => $imageUrl
                    ]);
                    return $imageUrl;
                }
                
                $this->logger->debug('No og:image found on Last.fm artist page', ['artist' => $artistName]);
                return null;
                
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                // Any request exception (including proxy failures) - retry with different proxy
                $this->logger->warning('Proxy request failed, retrying...', [
                    'proxy' => $proxy,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                continue;
            } catch (Throwable $e) {
                // Other errors (non-HTTP) - don't retry
                $this->logger->warning('Failed to fetch Last.fm artist page', [
                    'artist' => $artistName,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
        
        $this->logger->warning('All proxy attempts failed for Last.fm artist page', [
            'artist' => $artistName,
            'attempts' => $maxRetries
        ]);
        return null;
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
                $searchUrl = $apiBase . '/artist/?query=' . urlencode('artist:' . $artistName) . '&fmt=json&limit=1';
                $this->logger->debug('MusicBrainz artist search', ['url' => $searchUrl]);
                
                $res = $this->http->get($searchUrl, $httpOptions);
                
                if ($res->getStatusCode() !== 200) {
                    return null;
                }
                
                $data = json_decode((string) $res->getBody(), true);
                $mbid = $data['artists'][0]['id'] ?? null;
                
                if ($mbid === null) {
                    $this->logger->debug('MusicBrainz: artist not found', ['artist' => $artistName]);
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
