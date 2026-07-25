<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Last.fm removed the artist image API in 2019, so images are read from the
 * og:image meta tag of the public artist page.
 */
final class ArtistImageScraper
{
    public function __construct(private readonly ProxiedHttp $http) {}

    public function imageUrlFor(string $artistName): ?string
    {
        $url = ArtistUrl::for($artistName);

        Log::channel('app')->debug('Fetching Last.fm artist page', ['url' => $url]);

        // 200 and 404 are definitive answers; anything else triggers the proxy fallback.
        $res = $this->http->get(
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

    public function download(string $imageUrl, string $artistName, string $context = 'image-binary'): ?string
    {
        $res = $this->http->get(
            $imageUrl,
            $this->imageBinaryOptions(),
            static fn (Response $r) => $r->status() >= 200 && $r->status() < 300,
            $context,
            ['artist' => $artistName, 'url' => $imageUrl]
        );

        if ($res === null) {
            return null;
        }

        $bin = $res->body();

        return $bin !== '' ? $bin : null;
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
            if (in_array($property, ['og:image', 'og:image:secure_url', 'og:image:url'], true)) {
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
        ];
    }

    /**
     * Full Chrome header set, to reduce bot-detection hits.
     *
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
        ];
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
}
