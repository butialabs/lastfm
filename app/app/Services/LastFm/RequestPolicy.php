<?php

declare(strict_types=1);

namespace App\Services\LastFm;

/**
 * How ProxiedHttp spreads a request over the proxy and the direct connection.
 *
 * The default (2 proxy attempts, then direct, no pause) is used by the web
 * flow; the strict policy is for manual batch jobs that must not expose the
 * server IP and must pace their traffic.
 */
final class RequestPolicy
{
    public function __construct(
        public readonly int $proxyAttempts = 2,
        public readonly bool $directFallback = true,
        public readonly int $minDelay = 0,
        public readonly int $maxDelay = 0,
    ) {}

    /**
     * Proxy only, $attempts tries, a random pause of $minDelay..$maxDelay
     * seconds before every request.
     */
    public static function strict(int $attempts, int $minDelay, int $maxDelay): self
    {
        $minDelay = max(0, $minDelay);

        return new self(max(1, $attempts), false, $minDelay, max($minDelay, $maxDelay));
    }

    public function pauses(): bool
    {
        return $this->maxDelay > 0;
    }
}
