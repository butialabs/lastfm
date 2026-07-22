<?php

declare(strict_types=1);

use App\Livewire\LoginForm;
use App\Livewire\SettingsForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Páginas públicas
|--------------------------------------------------------------------------
*/

it('shows the login page to guests', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('login-form');
});

it('redirects authenticated users from / to /settings', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect('/settings');
});

it('requires auth for /settings', function () {
    $this->get('/settings')->assertRedirect('/');
});

it('shows settings to authenticated users', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertOk()
        ->assertSeeLivewire('settings-form');
});

it('renders the profile link with the real username (no uncompiled blade)', function () {
    $user = User::factory()->create(['protocol' => 'at', 'username' => 'alice.bsky.social']);

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('https://bsky.app/profile/alice.bsky.social')
        ->assertSee('@alice.bsky.social')
        ->assertDontSee('{{ $user');
});

it('stores locale cookie and updates the user language', function () {
    $user = User::factory()->create(['language' => 'en']);

    $this->actingAs($user)
        ->post('/locale', ['locale' => 'pt-BR'])
        ->assertRedirect()
        ->assertCookie('locale', 'pt-BR');

    expect($user->fresh()->language)->toBe('pt-BR');
});

it('rejects invalid locales', function () {
    $this->post('/locale', ['locale' => 'xx'])
        ->assertSessionHasErrors('locale');
});

it('logs out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Montagem pública
|--------------------------------------------------------------------------
*/

it('serves montage images with cache headers', function () {
    Storage::fake('montage');
    $hash = md5('42');
    Storage::disk('montage')->put($hash.'.jpg', 'fake-jpeg-binary');

    $this->get("/montage/{$hash}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'max-age=86400, public');
});

it('rejects invalid montage hashes', function () {
    $this->get('/montage/not-a-hash')->assertNotFound();
    $this->get('/montage/'.str_repeat('a', 32))->assertNotFound(); // missing file
});

/*
|--------------------------------------------------------------------------
| Login Bluesky (Livewire)
|--------------------------------------------------------------------------
*/

it('logs in with Bluesky and creates the user with encrypted password', function () {
    Http::fake([
        'https://bsky.social/xrpc/*' => Http::response([
            'did' => 'did:plc:alice',
            'accessJwt' => 'jwt',
            'refreshJwt' => 'refresh',
        ]),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'at')
        ->set('instance', 'https://bsky.social')
        ->set('username', 'alice.bsky.social')
        ->set('password', 'app-password-123')
        ->call('loginBluesky')
        ->assertRedirect('/settings');

    $user = User::where('username', 'alice.bsky.social')->sole();
    expect($user->did)->toBe('did:plc:alice')
        ->and(Crypt::decryptString($user->password))->toBe('app-password-123')
        ->and($user->status)->toBe(User::STATUS_ACTIVE);

    expect(Auth::id())->toBe($user->id);
});

it('shows an error when Bluesky login fails', function () {
    Http::fake([
        'https://bsky.social/xrpc/*' => Http::response(['error' => 'AuthenticationRequired'], 401),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'at')
        ->set('username', 'alice.bsky.social')
        ->set('password', 'wrong')
        ->call('loginBluesky')
        ->assertNoRedirect()
        ->assertSet('errorMessage', __('messages.error.auth_failed'));

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| OAuth Mastodon (callback)
|--------------------------------------------------------------------------
*/

it('completes the Mastodon OAuth flow', function () {
    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response(['access_token' => 'token-xyz']),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response(['acct' => 'bob']),
    ]);

    $this->withSession([
        'mastodon_state' => 'state-123',
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'cid',
        'mastodon_client_secret' => 'secret',
    ])->get('/auth/mastodon/callback?state=state-123&code=code-abc')
        ->assertRedirect('/settings');

    $user = User::where('username', 'bob')->sole();
    expect($user->protocol)->toBe(User::PROTOCOL_MASTODON)
        ->and(Crypt::decryptString($user->token))->toBe('token-xyz');

    expect(Auth::id())->toBe($user->id);
});

it('rejects Mastodon callback with invalid state', function () {
    $this->withSession([
        'mastodon_state' => 'state-123',
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'cid',
        'mastodon_client_secret' => 'secret',
    ])->get('/auth/mastodon/callback?state=wrong&code=code-abc')
        ->assertRedirect('/');

    $this->assertGuest();
});

/*
|--------------------------------------------------------------------------
| Settings (Livewire)
|--------------------------------------------------------------------------
*/

it('saves settings converting the schedule to UTC', function () {
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(['user' => ['name' => 'alice']]),
    ]);

    $user = User::factory()->create([
        'lastfm_username' => null,
        'day_of_week' => null,
        'time' => null,
        'timezone' => null,
    ]);

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->set('lastfm_username', 'alice')
        ->set('day_of_week', 5)
        ->set('hour', '09:00')
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        ->assertSet('flashMessage', __('messages.settings.saved'));

    $user->refresh();
    expect($user->lastfm_username)->toBe('alice')
        ->and($user->day_of_week)->toBe(5)
        ->and($user->time)->toBe('09:00:00')
        ->and($user->status)->toBe(User::STATUS_SCHEDULE);
});

it('converts local schedule to UTC across day boundaries', function () {
    // Friday 01:00 at UTC-3 (America/Sao_Paulo) = Friday 04:00 UTC
    [$dow, $time] = User::convertLocalScheduleToUtc(5, '01:00', new DateTimeZone('America/Sao_Paulo'));
    expect($dow)->toBe(5)->and($time)->toBe('04:00:00');

    // Monday 00:30 at UTC+14 (Pacific/Kiritimati) = Sunday 10:30 UTC
    [$dow, $time] = User::convertLocalScheduleToUtc(1, '00:30', new DateTimeZone('Pacific/Kiritimati'));
    expect($dow)->toBe(7)->and($time)->toBe('10:30:00');
});

it('rejects an unknown Last.fm user', function () {
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(['error' => 6, 'message' => 'User not found'], 200),
    ]);

    $user = User::factory()->create(['lastfm_username' => null]);

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->set('lastfm_username', 'ghost')
        ->set('day_of_week', 5)
        ->set('hour', '09:00')
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertHasErrors('lastfm_username');

    expect($user->fresh()->lastfm_username)->toBeNull();
});

it('deletes the account and its montage file', function () {
    Storage::fake('montage');
    $user = User::factory()->create(['social_montage' => '/montage/'.md5('1')]);
    Storage::disk('montage')->put(md5('1').'.jpg', 'fake');

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(User::count())->toBe(0);
    Storage::disk('montage')->assertMissing(md5('1').'.jpg');
    $this->assertGuest();
});
