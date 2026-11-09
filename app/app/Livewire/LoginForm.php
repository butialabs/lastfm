<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Social\BlueskyClient;
use App\Services\Social\MastodonClient;
use App\Services\Social\SocialAuthException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class LoginForm extends Component
{
    public const BLUESKY_DEFAULT_INSTANCE = 'https://bsky.social';

    /** Bluesky app passwords look like "abcd-efgh-ijkl-mnop". */
    private const APP_PASSWORD_PATTERN = '/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/i';

    /** '' = network selection | 'mastodon' | 'at' */
    public string $protocol = '';

    public string $instance = '';

    public string $username = '';

    public string $password = '';

    /** Bluesky: show the PDS field (only for people on a self-hosted server). */
    public bool $customServer = false;

    public ?string $errorMessage = null;

    /**
     * Field the current error belongs to, for aria-invalid / aria-describedby:
     * 'instance' | 'password' | 'credentials' (handle + password) | null.
     */
    public ?string $errorField = null;

    /** Neutral, non-error message (e.g. after logging out). */
    public ?string $noticeMessage = null;

    public function mount(): void
    {
        $this->noticeMessage = session('flash_notice');

        // Errors coming back from the OAuth callback.
        if (session()->has('flash')) {
            $this->errorMessage = (string) session('flash');
            $this->errorField = 'instance';
            $this->protocol = (string) session('flash_protocol', '');
            $this->instance = (string) session('flash_instance', '');
        }
    }

    /**
     * The step switch itself happens client-side (Alpine) so it is instant;
     * this call only syncs server state. Typed values survive going back and forth.
     */
    public function selectNetwork(string $protocol): void
    {
        $this->protocol = $protocol === 'at' ? 'at' : 'mastodon';

        if ($this->protocol === 'at' && ! $this->customServer) {
            $this->instance = self::BLUESKY_DEFAULT_INSTANCE;
        } elseif ($this->protocol === 'mastodon') {
            $this->customServer = false;
            if ($this->instance === self::BLUESKY_DEFAULT_INSTANCE) {
                $this->instance = '';
            }
        }

        $this->clearMessages();
    }

    public function back(): void
    {
        $this->protocol = '';
        $this->password = '';
        $this->clearMessages();
    }

    public function loginBluesky(BlueskyClient $bluesky)
    {
        $this->clearMessages();

        if (! $this->customServer || trim($this->instance) === '') {
            $this->instance = self::BLUESKY_DEFAULT_INSTANCE;
        }

        $this->validate([
            'instance' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->username = ltrim(trim($this->username), '@');
        $instance = $bluesky->normalizeInstance($this->instance);

        try {
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
            $this->failBluesky($e, $instance);

            return null;
        }

        Auth::login($user);
        session()->regenerate();

        return $this->redirect('/settings');
    }

    public function startMastodon(MastodonClient $mastodon)
    {
        $this->clearMessages();

        $this->validate([
            'instance' => ['required', 'string', 'max:255'],
        ]);

        $instance = $mastodon->normalizeInstance($this->instance);

        try {
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
            $this->errorField = 'instance';
            $this->errorMessage = match ($e instanceof SocialAuthException ? $e->reason : null) {
                SocialAuthException::UNREACHABLE => sprintf(__('messages.error.instance_unreachable'), self::host($instance)),
                SocialAuthException::NOT_A_SERVER => sprintf(__('messages.error.not_mastodon'), self::host($instance)),
                default => __('messages.error.auth_failed'),
            };

            return null;
        }

        return $this->redirect($url);
    }

    public function render()
    {
        try {
            $totalUsers = User::count();
        } catch (\Throwable) {
            $totalUsers = 0;
        }

        return view('livewire.login-form', ['totalUsers' => $totalUsers]);
    }

    private function failBluesky(\Throwable $e, string $instance): void
    {
        $reason = $e instanceof SocialAuthException ? $e->reason : null;

        if ($reason === SocialAuthException::REJECTED) {
            // A well-formed app password that was rejected may just as well be a wrong handle.
            $looksLikeAppPassword = preg_match(self::APP_PASSWORD_PATTERN, $this->password) === 1;
            $this->errorField = $looksLikeAppPassword ? 'credentials' : 'password';
            $this->errorMessage = $looksLikeAppPassword
                ? __('messages.error.invalid_credentials')
                : __('messages.error.main_password');

            return;
        }

        // Server problems belong to the server field: reveal it so it can be fixed.
        $this->errorField = 'instance';
        $this->customServer = true;
        $this->errorMessage = match ($reason) {
            SocialAuthException::UNREACHABLE => sprintf(__('messages.error.instance_unreachable'), self::host($instance)),
            SocialAuthException::NOT_A_SERVER => sprintf(__('messages.error.not_bluesky'), self::host($instance)),
            default => __('messages.error.auth_failed'),
        };
    }

    private function clearMessages(): void
    {
        $this->errorMessage = null;
        $this->errorField = null;
        $this->noticeMessage = null;
        $this->resetValidation();
    }

    private static function host(string $instance): string
    {
        return (string) (parse_url($instance, PHP_URL_HOST) ?: $instance);
    }
}
