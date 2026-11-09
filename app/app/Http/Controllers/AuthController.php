<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Social\MastodonClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function index(): RedirectResponse|View
    {
        if (Auth::check()) {
            return redirect('/settings');
        }

        return view('pages.login');
    }

    public function callbackMastodon(Request $request, MastodonClient $mastodon): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $sessionState = $request->session()->pull('mastodon_state');
        $instance = $request->session()->pull('mastodon_instance');
        $clientId = $request->session()->pull('mastodon_client_id');
        $clientSecret = $request->session()->pull('mastodon_client_secret');

        // The person declined on the instance's consent screen.
        if ($request->query('error') === 'access_denied') {
            return $this->failedMastodonLogin(__('messages.error.oauth_denied'), $instance);
        }

        if ($state === '' || $code === '' || ! is_string($sessionState) || ! hash_equals($sessionState, $state)
            || ! is_string($instance) || ! is_string($clientId) || ! is_string($clientSecret)) {
            return $this->failedMastodonLogin(instance: $instance);
        }

        try {
            $redirectUri = rtrim((string) config('app.url'), '/').'/auth/mastodon/callback';
            $token = $mastodon->exchangeToken($instance, $clientId, $clientSecret, $redirectUri, $code);
            $credentials = $mastodon->verifyCredentials($instance, $token);
            $username = (string) ($credentials['acct'] ?? '');

            if ($username === '') {
                throw new \RuntimeException('Mastodon verify_credentials returned no username');
            }

            $user = User::upsertMastodonUser(
                instance: $mastodon->normalizeInstance($instance),
                username: $username,
                encryptedToken: Crypt::encryptString($token),
                preferredLanguage: app()->getLocale(),
            );
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Mastodon callback failed', ['error' => $e->getMessage()]);

            return $this->failedMastodonLogin(__('messages.error.oauth_failed'), $instance);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/settings');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        session()->flash('flash_notice', __('messages.auth.logged_out'));

        return redirect('/');
    }

    private function failedMastodonLogin(?string $message = null, mixed $instance = null): RedirectResponse
    {
        session()->flash('flash', $message ?? __('messages.error.oauth_failed'));
        session()->flash('flash_protocol', 'mastodon');

        if (is_string($instance) && $instance !== '') {
            session()->flash('flash_instance', (string) (parse_url($instance, PHP_URL_HOST) ?: $instance));
        }

        return redirect('/');
    }
}
