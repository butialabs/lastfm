<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\CryptoService;
use App\Services\I18nService;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use League\Plates\Engine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CryptoService $crypto,
        private readonly BlueskyClient $bluesky,
        private readonly MastodonClient $mastodon,
        private readonly I18nService $i18n,
        private readonly Engine $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $userId = session_get('user_id');
        if (is_int($userId)) {
            return redirect('/settings');
        }

        $html = $this->views->render('login');
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function setLocale(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $body = (array) ($request->getParsedBody() ?? []);

        $locale = (string) ($body['locale'] ?? 'en');
        $locale = in_array($locale, ['en', 'pt-BR'], true) ? $locale : 'en';

        $cookie = $this->i18n->makeLocaleCookieHeader($locale);

        $userId = session_get('user_id');
        if (is_int($userId)) {
            $this->users->updateLanguage($userId, $locale);
        }

        $referer = $request->getHeaderLine('Referer');
        $target = $referer !== '' ? (string) (parse_url($referer, PHP_URL_PATH) ?? '/') : '/';
        if ($target === '') {
            $target = '/';
        }

        return new Response(302, ['Location' => $target, 'Set-Cookie' => $cookie]);
    }

    public function loginBluesky(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $body = (array) ($request->getParsedBody() ?? []);

        $instance = trim((string) ($body['instance'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            flash('flash', __('error.missing_fields'));
            flash('flash_protocol', 'at');
            return redirect('/');
        }

        try {
            $session = $this->bluesky->createSession($instance, $username, $password);
            $encPassword = $this->crypto->encrypt($password);

            $preferredLanguage = $this->i18n->resolveLocaleFromRequestOrCookie($request);
            $userId = $this->users->upsertAtUser(
                instance: $this->bluesky->normalizeInstance($instance),
                username: $username,
                did: $session['did'],
                encryptedPassword: $encPassword,
                preferredLanguage: $preferredLanguage,
            );

            session_set('user_id', $userId);
            $res = redirect('/settings');
            return $res->withHeader('Set-Cookie', $this->i18n->makeLocaleCookieHeader($preferredLanguage));
        } catch (\Throwable $e) {
            try {
                $this->logger->error('Bluesky login failed', [
                    'instance' => $instance,
                    'username' => $username,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            } catch (\Throwable $logErr) {
                error_log('Bluesky login failed; and logger failed: ' . $logErr->getMessage());
            }
            flash('flash', __('error.auth_failed'));
            flash('flash_protocol', 'at');
            return redirect('/');
        }
    }

    public function startMastodon(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $query = $request->getQueryParams();
        $instance = trim((string) ($query['instance'] ?? ''));
        if ($instance === '') {
            flash('flash', __('error.missing_fields'));
            flash('flash_protocol', 'mastodon');
            return redirect('/');
        }

        $instance = $this->mastodon->normalizeInstance($instance);
        $redirectUri = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/mastodon/callback';
        if (!str_starts_with($redirectUri, 'http')) {
            throw new \RuntimeException('APP_URL must be configured for Mastodon OAuth');
        }

        $state = bin2hex(random_bytes(16));
        session_set('mastodon_state', $state);
        session_set('mastodon_instance', $instance);

        $app = $this->mastodon->registerApp($instance, 'LastFM.blue', $redirectUri);
        session_set('mastodon_client_id', $app['client_id']);
        session_set('mastodon_client_secret', $app['client_secret']);

        $authUrl = $this->mastodon->getAuthorizeUrl(
            instance: $instance,
            clientId: $app['client_id'],
            redirectUri: $redirectUri,
            scope: 'read write',
            state: $state
        );

        return new Response(302, ['Location' => $authUrl]);
    }

    public function callbackMastodon(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $query = $request->getQueryParams();

        $state = (string) ($query['state'] ?? '');
        $code = (string) ($query['code'] ?? '');

        if ($state === '' || $code === '' || $state !== (string) session_get('mastodon_state')) {
            flash('flash', __('error.auth_failed'));
            flash('flash_protocol', 'mastodon');
            return redirect('/');
        }

        $instance = (string) session_get('mastodon_instance');
        $clientId = (string) session_get('mastodon_client_id');
        $clientSecret = (string) session_get('mastodon_client_secret');
        $redirectUri = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/mastodon/callback';

        try {
            $token = $this->mastodon->exchangeToken($instance, $clientId, $clientSecret, $redirectUri, $code);
            $account = $this->mastodon->verifyCredentials($instance, $token);
            $username = (string) ($account['acct'] ?? $account['username'] ?? '');
            if ($username === '') {
                throw new \RuntimeException('Unable to read Mastodon account username');
            }

            $encToken = $this->crypto->encrypt($token);

            $preferredLanguage = $this->i18n->resolveLocaleFromRequestOrCookie($request);
            $userId = $this->users->upsertMastodonUser(
                instance: $instance,
                username: $username,
                encryptedToken: $encToken,
                preferredLanguage: $preferredLanguage,
            );

            session_set('user_id', $userId);
            $res = redirect('/settings');
            return $res->withHeader('Set-Cookie', $this->i18n->makeLocaleCookieHeader($preferredLanguage));
        } catch (\Throwable $e) {
            try {
                $this->logger->error('Mastodon OAuth failed', [
                    'instance' => $instance ?? null,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            } catch (\Throwable $logErr) {
                error_log('Mastodon OAuth failed; and logger failed: ' . $logErr->getMessage());
            }
            flash('flash', __('error.auth_failed'));
            flash('flash_protocol', 'mastodon');
            return redirect('/');
        }
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        session_destroy_safe();
        flash('flash', __('auth.logged_out'));
        return redirect('/');
    }

    public function deleteAccount(ServerRequestInterface $request): ResponseInterface
    {
        session_start_safe();
        $userId = session_get('user_id');
        if (!is_int($userId)) {
            return redirect('/');
        }

        $user = $this->users->findById($userId);
        if ($user !== null && is_string($user['social_montage'] ?? null)) {
            $this->users->deleteMontageFile((string) $user['social_montage']);
        }

        $this->users->deleteById($userId);
        session_destroy_safe();
        flash('flash', __('auth.account_deleted'));
        return redirect('/');
    }
}

