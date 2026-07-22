<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Locale resolution: valid 'locale' cookie → Accept-Language
     * (pt* → pt-BR, fr* → fr-FR, otherwise en) — same rule as the legacy app.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locales = (array) config('lastfm.locales', ['en']);
        $candidate = $request->cookie('locale');

        if (! is_string($candidate) || ! in_array($candidate, $locales, true)) {
            $accept = strtolower((string) $request->header('Accept-Language', ''));

            $candidate = match (true) {
                str_starts_with($accept, 'pt') => 'pt-BR',
                str_starts_with($accept, 'fr') => 'fr-FR',
                default => 'en',
            };
        }

        if (! in_array($candidate, $locales, true)) {
            $candidate = 'en';
        }

        App::setLocale($candidate);

        return $next($request);
    }
}
