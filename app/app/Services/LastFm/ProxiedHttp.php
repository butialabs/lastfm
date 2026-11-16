<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Shared transport for every outbound Last.fm request.
 *
 * Last.fm blocks bursts of traffic (403/429). With the default policy each
 * request is attempted through the configured proxy twice before falling back
 * to a direct call; RequestPolicy::strict() keeps every attempt on the proxy
 * and pauses a random number of seconds before each one.
 *
 * LASTFM_PROXY_URL accepts a comma-separated list; each attempt picks one at
 * random.
 */
final class ProxiedHttp
{
    private readonly RequestPolicy $policy;

    public function __construct(?RequestPolicy $policy = null)
    {
        $this->policy = $policy ?? new RequestPolicy;
    }

    public function withPolicy(RequestPolicy $policy): self
    {
        return new self($policy);
    }

    public function policy(): RequestPolicy
    {
        return $this->policy;
    }

    /**
     * Returns the first response satisfying $isSuccess, or null once every
     * attempt is exhausted.
     *
     * @param  array<string,mixed>  $options
     * @param  callable(Response):bool  $isSuccess
     * @param  array<string,mixed>  $logCtx
     *
     * @throws \RuntimeException when the policy requires a proxy and none is configured
     */
    public function get(string $url, array $options, callable $isSuccess, string $context, array $logCtx = []): ?Response
    {
        foreach ($this->attempts() as $attempt => $proxy) {
            $this->pause($context, $attempt, $logCtx);

            $opts = $options;
            $via = 'direct';

            if ($proxy !== null) {
                $via = 'proxy';
                $opts['proxy'] = $proxy;
                $opts['timeout'] = 25;
                $opts['connect_timeout'] = 10;
                // Relaxed for the proxy's own TLS only; the tunnelled request to
                // Last.fm is still verified end to end.
                $opts['curl'] = ($opts['curl'] ?? []) + [
                    CURLOPT_PROXY_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY_SSL_VERIFYHOST => 0,
                ];
            }

            try {
                $res = Http::withOptions($opts)->get($url);

                if ($isSuccess($res)) {
                    Log::channel('app')->debug('Last.fm request succeeded', $logCtx + [
                        'context' => $context,
                        'via' => $via,
                        'attempt' => $attempt + 1,
                        'status' => $res->status(),
                    ]);

                    return $res;
                }

                Log::channel('app')->debug('Last.fm request non-success', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'attempt' => $attempt + 1,
                    'status' => $res->status(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('app')->warning('Last.fm request failed', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function proxyUrl(): ?string
    {
        return $this->proxyUrls()[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function proxyUrls(): array
    {
        $urls = array_map('trim', explode(',', (string) config('lastfm.proxy_url', '')));

        return array_values(array_filter($urls, static fn (string $u) => $u !== ''));
    }

    /**
     * @return list<string|null>
     */
    private function attempts(): array
    {
        $proxies = $this->proxyUrls();

        if ($proxies === []) {
            if (! $this->policy->directFallback) {
                throw new \RuntimeException('LASTFM_PROXY_URL is required by the current request policy');
            }

            return [null];
        }

        $attempts = [];
        for ($i = 0; $i < max(1, $this->policy->proxyAttempts); $i++) {
            $attempts[] = $proxies[array_rand($proxies)];
        }

        if ($this->policy->directFallback) {
            $attempts[] = null;
        }

        return $attempts;
    }

    /**
     * @param  array<string,mixed>  $logCtx
     */
    private function pause(string $context, int $attempt, array $logCtx): void
    {
        if (! $this->policy->pauses()) {
            return;
        }

        $seconds = random_int($this->policy->minDelay, $this->policy->maxDelay);

        Log::channel('app')->debug('Pausing before Last.fm request', $logCtx + [
            'context' => $context,
            'attempt' => $attempt + 1,
            'seconds' => $seconds,
        ]);

        Sleep::for($seconds)->seconds();
    }
}
