<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class LoginForm extends Component
{
    /** '' = network selection | 'mastodon' | 'at' */
    public string $protocol = '';

    public string $instance = 'https://mastodon.social';

    public string $username = '';

    public string $password = '';

    public ?string $errorMessage = null;

    public function mount(): void
    {
        // Errors coming back from the OAuth callback (session flash, as in the legacy app).
        if (session()->has('flash')) {
            $this->errorMessage = (string) session('flash');
            $this->protocol = (string) session('flash_protocol', '');
        }

        if ($this->protocol === 'at') {
            $this->instance = 'https://bsky.social';
        }
    }

    public function selectNetwork(string $protocol): void
    {
        $this->protocol = $protocol === 'at' ? 'at' : 'mastodon';
        $this->instance = $this->protocol === 'at' ? 'https://bsky.social' : 'https://mastodon.social';
        $this->errorMessage = null;
        $this->resetValidation();
    }

    public function back(): void
    {
        $this->protocol = '';
        $this->username = '';
        $this->password = '';
        $this->instance = 'https://mastodon.social';
        $this->errorMessage = null;
        $this->resetValidation();
    }

    public function loginBluesky(BlueskyClient $bluesky)
    {
        $this->validate([
            'instance' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        try {
            $instance = $bluesky->normalizeInstance($this->instance);
            $session = $bluesky->createSession($instance, $this->username, $this->password);

            $user = User::upsertAtUser(
                instance: $instance,
                username: $this->username,
                did: $session['did'],
                encryptedPassword: Crypt::encryptString($this->password),
                preferredLanguage: app()->getLocale(),
            );
        } catch (\Throwable $e) {
            Log::channel('app')->info('Bluesky login failed', ['error' => $e->getMessage()]);
            $this->errorMessage = __('messages.error.auth_failed');

            return null;
        }

        Auth::login($user);
        session()->regenerate();

        return $this->redirect('/settings');
    }

    public function startMastodon(MastodonClient $mastodon)
    {
        $this->validate([
            'instance' => ['required', 'string', 'max:255'],
        ]);

        try {
            $instance = $mastodon->normalizeInstance($this->instance);
            $redirectUri = rtrim((string) config('app.url'), '/').'/auth/mastodon/callback';
            $app = $mastodon->registerApp($instance, 'LastFM.blue', $redirectUri);

            $state = bin2hex(random_bytes(16));
            session()->put('mastodon_state', $state);
            session()->put('mastodon_instance', $instance);
            session()->put('mastodon_client_id', $app['client_id']);
            session()->put('mastodon_client_secret', $app['client_secret']);

            $url = $mastodon->getAuthorizeUrl($instance, $app['client_id'], $redirectUri, 'read write', $state);
        } catch (\Throwable $e) {
            Log::channel('app')->info('Mastodon OAuth start failed', ['error' => $e->getMessage()]);
            $this->errorMessage = __('messages.error.auth_failed');

            return null;
        }

        return $this->redirect($url);
    }

    public function render()
    {
        return view('livewire.login-form');
    }
}
