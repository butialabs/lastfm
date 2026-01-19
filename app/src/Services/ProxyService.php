<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;

/**
 * Service for managing proxy list used for HTTP requests.
 */
class ProxyService
{
    private const PROXY_FILE_NAME = '.proxy';

    private LoggerInterface $logger;

    /**
     * ProxyService constructor.
     *
     * @param LoggerInterface $logger Logger instance for logging operations
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Fetches the proxy list from the remote URL and saves it locally.
     *
     * @return bool True on success, false on failure
     */
    public function refresh(): bool
    {
        $provider = trim((string) ($_ENV['PROXY_PROVIDER'] ?? ''));
        $url = trim((string) ($_ENV['PROXY_URL'] ?? ''));

        if ($provider === '' || $url === '') {
            $this->logger->debug('Proxy refresh skipped: PROXY_PROVIDER or PROXY_URL not configured');
            return false;
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'ignore_errors' => false,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $content = @file_get_contents($url, false, $context);

            if ($content === false) {
                $this->logger->warning('Failed to fetch proxy list', ['url' => $url]);
                return false;
            }

            $lines = array_filter(
                array_map('trim', explode("\n", $content)),
                fn($line) => $line !== '' && !str_starts_with($line, '#')
            );

            $formattedProxies = [];
            foreach ($lines as $line) {
                $formattedProxies[] = $this->formatProxyUrl($line);
            }

            $filePath = $this->getProxyFilePath();
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($filePath, implode("\n", $formattedProxies));

            $this->logger->info('Proxy list refreshed', [
                'provider' => $provider,
                'count' => count($formattedProxies)
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to refresh proxy list', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Returns a random proxy from the saved list, excluding any already used.
     *
     * @param array<string> $exclude Proxies to exclude from selection
     * @return string|null Proxy in format "http://USER:PASSWORD@HOST:PORT" or null if no proxies available
     */
    public function getRandomProxy(array $exclude = []): ?string
    {
        $filePath = $this->getProxyFilePath();

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return null;
        }

        $proxies = array_filter(
            array_map('trim', explode("\n", $content)),
            fn($line) => $line !== '' && !str_starts_with($line, '#')
        );

        if (empty($proxies)) {
            return null;
        }

        $availableProxies = array_filter($proxies, fn($p) => !in_array($p, $exclude, true));

        if (empty($availableProxies)) {
            return null;
        }

        return $availableProxies[array_rand($availableProxies)];
    }

    /**
     * Format proxy string to Guzzle-compatible URL
     *
     * @param string $proxy Raw proxy string
     * @return string Formatted proxy URL for Guzzle
     */
    private function formatProxyUrl(string $proxy): string
    {
        $parts = explode(':', $proxy);

        if (count($parts) === 4) {
            // Format: HOST:PORT:USER:PASSWORD
            [$host, $port, $user, $pass] = $parts;
            return "http://{$user}:{$pass}@{$host}:{$port}";
        } elseif (count($parts) === 2) {
            // Format: HOST:PORT (no auth)
            [$host, $port] = $parts;
            return "http://{$host}:{$port}";
        }

        return "http://{$proxy}";
    }

    /**
     * Returns the absolute path to the proxy list file.
     *
     * @return string Absolute path to the proxy list file
     */
    public function getProxyFilePath(): string
    {
        return dirname(__DIR__, 2) . '/data/db/' . self::PROXY_FILE_NAME;
    }
}
