<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class I18nService
{
    public function __construct(
        private readonly string $basePath,
        private readonly \App\Repositories\UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveLocaleFromRequestOrCookie(ServerRequestInterface $request): string
    {
        $cookie = $request->getCookieParams()['locale'] ?? null;
        if (is_string($cookie) && in_array($cookie, ['en', 'pt-BR'], true)) {
            return $cookie;
        }

        $accept = strtolower($request->getHeaderLine('Accept-Language'));
        return str_starts_with($accept, 'pt') ? 'pt-BR' : 'en';
    }

    public function makeLocaleCookieHeader(string $locale): string
    {
        $maxAge = 60 * 60 * 24 * 365;
        return sprintf('locale=%s; Path=/; Max-Age=%d; SameSite=Lax', $locale, $maxAge);
    }
}

