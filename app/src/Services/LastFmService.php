<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ArtistRepository;
use GuzzleHttp\Client as Guzzle;
use Psr\Log\LoggerInterface;
use Throwable;

final class LastFmService
{
    private string $cacheDir;
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

            $artistUrl = 'https://www.last.fm/music/' . rawurlencode($name);
            
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
     * (Last.fm → Archive.org → MusicBrainz), overwriting any cached copy.
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
            $result = $this->fetchAndSaveFromArchiveOrg($artist['name'], $path);
        }
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
            $artistUrl = 'https://www.last.fm/music/' . rawurlencode($artistName);
            
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

        // 2. Try Archive.org
        $result = $this->fetchAndSaveFromArchiveOrg($artistName, $path);
        if ($result !== '') {
            if (empty($artist['image_hash'])) {
                $this->artistRepository->update((int)$artist['id'], [
                    'image_hash' => $hash
                ]);
            }
            return $result;
        }

        // 3. MusicBrainz
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
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? 'https://lastfm.blue'));
        $userAgent = 'LastFM.blue/1.0 ( ' . $appUrl . ' )';
        
        $artistSlug = rawurlencode($artistName);
        $url = 'https://www.last.fm/music/' . $artistSlug;
        
        $this->logger->debug('Fetching Last.fm artist page', ['url' => $url]);
        
        $options = [
            'headers' => [
                'Accept' => 'text/html',
                'User-Agent' => $userAgent,
            ],
            'timeout' => 10,
            'connect_timeout' => 5,
        ];
        
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
            
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch Last.fm artist page', [
                'artist' => $artistName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Fetch and save artist image using Archive.org
     */
    private function fetchAndSaveFromArchiveOrg(string $artistName, string $path): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? 'https://lastfm.blue'));
        $userAgent = 'LastFM.blue/1.0 ( ' . $appUrl . ' )';
        
        $artistSlug = rawurlencode($artistName);
        $lastFmUrl = 'https://www.last.fm/music/' . $artistSlug;
        
        $this->logger->debug('Checking Archive.org for Last.fm artist page', ['url' => $lastFmUrl]);
        
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => $userAgent,
            ],
            'timeout' => 15,
            'connect_timeout' => 5,
        ];
        
        try {
            $waybackApiUrl = 'https://archive.org/wayback/available?url=' . urlencode($lastFmUrl);
            $res = $this->http->get($waybackApiUrl, $options);
            
            if ($res->getStatusCode() !== 200) {
                $this->logger->debug('Archive.org API returned non-200', [
                    'status' => $res->getStatusCode()
                ]);
                return '';
            }
            
            $data = json_decode((string) $res->getBody(), true);
            $snapshotUrl = $data['archived_snapshots']['closest']['url'] ?? null;
            
            if ($snapshotUrl === null) {
                $this->logger->debug('No Archive.org snapshot available', ['artist' => $artistName]);
                return '';
            }
            
            $this->logger->debug('Found Archive.org snapshot', ['url' => $snapshotUrl]);
            
            $htmlOptions = [
                'headers' => [
                    'Accept' => 'text/html',
                    'User-Agent' => $userAgent,
                ],
                'timeout' => 15,
                'connect_timeout' => 5,
            ];
            
            $res = $this->http->get($snapshotUrl, $htmlOptions);
            
            if ($res->getStatusCode() !== 200) {
                $this->logger->debug('Archive.org snapshot page returned non-200', [
                    'status' => $res->getStatusCode()
                ]);
                return '';
            }
            
            $html = (string) $res->getBody();
            
            $archiveImageUrl = null;
            if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                $archiveImageUrl = $matches[1];
            } elseif (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/i', $html, $matches)) {
                $archiveImageUrl = $matches[1];
            }
            
            if ($archiveImageUrl === null) {
                $this->logger->debug('No og:image found in Archive.org snapshot', ['artist' => $artistName]);
                return '';
            }
            
            $this->logger->debug('Found og:image in Archive.org snapshot', ['imageUrl' => $archiveImageUrl]);
            
            $directImageUrl = $this->extractDirectUrlFromArchive($archiveImageUrl);
            
            if ($directImageUrl !== null) {
                $this->logger->debug('Trying direct image URL', ['url' => $directImageUrl]);
                
                try {
                    $res = $this->http->get($directImageUrl, [
                        'headers' => [
                            'Accept' => 'image/*',
                            'User-Agent' => $userAgent,
                        ],
                        'timeout' => 10,
                        'connect_timeout' => 5,
                    ]);
                    
                    if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                        $bin = (string) $res->getBody();
                        if ($bin !== '') {
                            file_put_contents($path, $bin);
                            $this->logger->info('Downloaded image from direct URL', [
                                'artist' => $artistName,
                                'url' => $directImageUrl
                            ]);
                            return $path;
                        }
                    }
                } catch (Throwable $e) {
                    $this->logger->debug('Direct image URL download failed', [
                        'url' => $directImageUrl,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->logger->debug('Trying Archive.org image URL', ['url' => $archiveImageUrl]);
            
            try {
                $res = $this->http->get($archiveImageUrl, [
                    'headers' => [
                        'Accept' => 'image/*',
                        'User-Agent' => $userAgent,
                    ],
                    'timeout' => 15,
                    'connect_timeout' => 5,
                ]);
                
                if ($res->getStatusCode() >= 200 && $res->getStatusCode() < 300) {
                    $bin = (string) $res->getBody();
                    if ($bin !== '') {
                        file_put_contents($path, $bin);
                        $this->logger->info('Downloaded image from Archive.org', [
                            'artist' => $artistName,
                            'url' => $archiveImageUrl
                        ]);
                        return $path;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Archive.org image download failed', [
                    'url' => $archiveImageUrl,
                    'error' => $e->getMessage()
                ]);
            }
            
        } catch (Throwable $e) {
            $this->logger->warning('Archive.org fetch failed', [
                'artist' => $artistName,
                'error' => $e->getMessage()
            ]);
        }
        
        return '';
    }

    /**
     * Extract direct URL from Archive.org URL format
     */
    private function extractDirectUrlFromArchive(string $archiveUrl): ?string
    {
        if (preg_match('#https?://web\.archive\.org/web/\d+(?:im_|id_|if_|js_|cs_)?/(https?://.+)#i', $archiveUrl, $matches)) {
            return $matches[1];
        }
        
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
