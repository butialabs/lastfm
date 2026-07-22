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

        if ($state === '' || $code === '' || ! is_string($sessionState) || ! hash_equals($sessionState, $state)
            || ! is_string($instance) || ! is_string($clientId) || ! is_string($clientSecret)) {
            return $this->failedMastodonLogin();
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

            return $this->failedMastodonLogin();
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

        session()->flash('flash', __('messages.auth.logged_out'));

        return redirect('/');
    }

    private function failedMastodonLogin(): RedirectResponse
    {
        session()->flash('flash', __('messages.error.auth_failed'));
        session()->flash('flash_protocol', 'mastodon');

        return redirect('/');
    }
}
