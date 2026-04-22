<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client as Guzzle;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Headless browser fetch client
 */
final class BrowserFetchClient
{
    private const MODE_SIDECAR = 'sidecar';
    private const MODE_BROWSERLESS = 'browserless';

    private Guzzle $http;
    private LoggerInterface $logger;
    private string $mode;
    private string $baseUrl;
    private string $token;

    public function __construct(Guzzle $http, LoggerInterface $logger)
    {
        $this->http = $http;
        $this->logger = $logger;

        $mode = strtolower(trim((string) ($_ENV['LASTFM_BROWSER_FETCH_MODE'] ?? '')));
        $this->mode = in_array($mode, [self::MODE_SIDECAR, self::MODE_BROWSERLESS], true) ? $mode : '';

        $this->baseUrl = rtrim(trim((string) ($_ENV['LASTFM_BROWSER_FETCH_URL'] ?? '')), '/');
        $this->token = trim((string) ($_ENV['LASTFM_BROWSERLESS_TOKEN'] ?? ''));

        if ($this->mode === self::MODE_BROWSERLESS && $this->baseUrl === '') {
            $this->baseUrl = 'https://chrome.browserless.io';
        }
    }

    public function isAvailable(): bool
    {
        if ($this->mode === self::MODE_SIDECAR) {
            return $this->baseUrl !== '';
        }
        if ($this->mode === self::MODE_BROWSERLESS) {
            return $this->token !== '';
        }
        return false;
    }

    public function get(string $url, int $timeout = 30): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $minMs = max(0, (int) ($_ENV['LASTFM_BROWSER_FETCH_JITTER_MIN_MS'] ?? 500));
        $maxMs = max($minMs, (int) ($_ENV['LASTFM_BROWSER_FETCH_JITTER_MAX_MS'] ?? 2500));
        $delayMs = $minMs === $maxMs ? $minMs : random_int($minMs, $maxMs);
        if ($delayMs > 0) {
            $this->logger->debug('browser-fetch: jitter before request', [
                'mode' => $this->mode,
                'delayMs' => $delayMs,
            ]);
            usleep($delayMs * 1000);
        }

        $endpoint = $this->baseUrl . '/content';
        $options = [
            'timeout' => $timeout,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'text/html,*/*;q=0.8',
            ],
            'json' => [
                'url' => $url,
                'gotoOptions' => [
                    'waitUntil' => 'domcontentloaded',
                    'timeout' => ($timeout - 5) * 1000,
                ],
                'rejectResourceTypes' => [
                    'stylesheet',
                    'image',
                    'media',
                    'font',
                    'script',
                    'texttrack',
                    'xhr',
                    'fetch',
                    'eventsource',
                    'websocket',
                    'manifest',
                    'other',
                ],
                'bestAttempt' => true,
            ],
        ];

        if ($this->mode === self::MODE_BROWSERLESS) {
            $options['query'] = ['token' => $this->token];
        }

        try {
            $res = $this->http->post($endpoint, $options);
            $status = $res->getStatusCode();
            $body = (string) $res->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->debug('browser-fetch: non-2xx from browser service', [
                    'mode' => $this->mode,
                    'status' => $status,
                    'snippet' => substr($body, 0, 200),
                ]);
                return ['status' => $status, 'body' => ''];
            }

            return ['status' => 200, 'body' => $body];
        } catch (Throwable $e) {
            $this->logger->warning('browser-fetch: request failed', [
                'mode' => $this->mode,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }
}
