<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared transport for every outbound Last.fm request.
 *
 * Last.fm blocks bursts of traffic (403/429), so each request is attempted
 * through the configured proxy twice before falling back to a direct call.
 */
final class ProxiedHttp
{
    /**
     * Returns the first response satisfying $isSuccess, or null once every
     * attempt is exhausted.
     *
     * @param  array<string,mixed>  $options
     * @param  callable(Response):bool  $isSuccess
     * @param  array<string,mixed>  $logCtx
     */
    public function get(string $url, array $options, callable $isSuccess, string $context, array $logCtx = []): ?Response
    {
        foreach ($this->attempts() as $proxy) {
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
                        'status' => $res->status(),
                    ]);

                    return $res;
                }

                Log::channel('app')->debug('Last.fm request non-success', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'status' => $res->status(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('app')->warning('Last.fm request failed', $logCtx + [
                    'context' => $context,
                    'via' => $via,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function proxyUrl(): ?string
    {
        $url = trim((string) config('lastfm.proxy_url', ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @return list<string|null>
     */
    private function attempts(): array
    {
        $proxy = $this->proxyUrl();

        return $proxy !== null ? [$proxy, $proxy, null] : [null];
    }
}
