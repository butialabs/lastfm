<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;

/**
 * Wrapper the curl-impersonate binary.
 */
final class CurlImpersonateClient
{
    private LoggerInterface $logger;
    private string $binPath;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->binPath = trim((string) ($_ENV['LASTFM_CURL_IMPERSONATE_BIN'] ?? ''))
            ?: '/usr/local/bin/curl_chrome116';
    }

    public function isAvailable(): bool
    {
        return is_file($this->binPath) && is_executable($this->binPath);
    }

    public function get(string $url, array $headers = [], int $timeout = 20, int $connectTimeout = 10): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $marker = '===CI_STATUS_' . bin2hex(random_bytes(6)) . '===';

        $cmd = [
            $this->binPath,
            '-s',
            '-L',
            '--compressed',
            '--max-time', (string) $timeout,
            '--connect-timeout', (string) $connectTimeout,
            '-w', $marker . '%{http_code}',
        ];

        foreach ($headers as $name => $value) {
            $cmd[] = '-H';
            $cmd[] = $name . ': ' . $value;
        }

        $cmd[] = $url;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->logger->warning('curl-impersonate: failed to start process', ['bin' => $this->binPath]);
            return null;
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->logger->debug('curl-impersonate: non-zero exit', [
                'exit' => $exitCode,
                'stderr' => substr($stderr, 0, 500),
                'url' => $url,
            ]);
            return null;
        }

        $pos = strrpos($stdout, $marker);
        if ($pos === false) {
            $this->logger->debug('curl-impersonate: status marker not found', ['url' => $url]);
            return null;
        }

        $body = substr($stdout, 0, $pos);
        $statusStr = substr($stdout, $pos + strlen($marker));
        $status = (int) $statusStr;

        if ($body !== '' && substr($body, -1) === "\n") {
            $body = substr($body, 0, -1);
        }

        return ['status' => $status, 'body' => $body];
    }
}
