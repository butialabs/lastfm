<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;
use Raza\PHPImpersonate\Exception\RequestException;
use Raza\PHPImpersonate\PHPImpersonate;
use Throwable;

/**
 * Wrapper the curl-impersonate binary.
 */
final class CurlImpersonateClient
{
    private const BROWSER = 'chrome116';
    private const FINGERPRINT_HEADERS = [
        'accept',
        'accept-encoding',
        'accept-language',
        'user-agent',
        'upgrade-insecure-requests',
        'sec-ch-ua',
        'sec-ch-ua-mobile',
        'sec-ch-ua-platform',
        'sec-fetch-site',
        'sec-fetch-mode',
        'sec-fetch-user',
        'sec-fetch-dest',
    ];

    private LoggerInterface $logger;
    private ?bool $available = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            new PHPImpersonate(self::BROWSER);
            $this->available = true;
        } catch (Throwable $e) {
            $this->logger->debug('curl-impersonate: library unavailable', [
                'error' => $e->getMessage(),
            ]);
            $this->available = false;
        }

        return $this->available;
    }

    public function get(string $url, array $headers = [], int $timeout = 20, int $connectTimeout = 10): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $safeHeaders = $this->stripFingerprintHeaders($headers);

        try {
            $client = new PHPImpersonate(self::BROWSER, max($timeout, 1), [
                'connect-timeout' => $connectTimeout,
            ]);
            $response = $client->sendGet($url, $safeHeaders);
        } catch (RequestException $e) {
            $this->logger->debug('curl-impersonate: request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (Throwable $e) {
            $this->logger->warning('curl-impersonate: unexpected error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    private function stripFingerprintHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $value) {
            if (\in_array(strtolower((string) $name), self::FINGERPRINT_HEADERS, true)) {
                continue;
            }
            $filtered[$name] = $value;
        }
        return $filtered;
    }
}
