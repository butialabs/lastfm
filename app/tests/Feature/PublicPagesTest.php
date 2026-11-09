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

beforeEach(function () {
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => null]);
});

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
        ->assertSet('errorField', 'password')
        ->assertSet('errorMessage', __('messages.error.main_password'));

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('tells a rejected app password apart from a main password', function () {
    Http::fake([
        'https://bsky.social/xrpc/*' => Http::response(['error' => 'AuthenticationRequired'], 401),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'at')
        ->set('username', 'alice.bsky.social')
        ->set('password', 'abcd-efgh-ijkl-mnop')
        ->call('loginBluesky')
        ->assertSet('errorField', 'credentials')
        ->assertSet('errorMessage', __('messages.error.invalid_credentials'))
        ->assertSeeHtml('id="bluesky-handle"')
        ->assertSeeHtml('aria-describedby="login-error"');
});

it('keeps what was typed when going back, except the password', function () {
    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'at')
        ->set('username', 'alice.bsky.social')
        ->set('password', 'abcd-efgh-ijkl-mnop')
        ->call('back')
        ->assertSet('protocol', '')
        ->assertSet('username', 'alice.bsky.social')
        ->assertSet('password', '')
        ->call('selectNetwork', 'mastodon')
        ->assertSet('instance', '')
        ->set('instance', 'fosstodon.org')
        ->call('back')
        ->call('selectNetwork', 'mastodon')
        ->assertSet('instance', 'fosstodon.org');
});

it('renders the sample post in the real post format', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Lua Vermelha (87)')
        ->assertSee('#myweekcounted')
        ->assertDontSee('{{ ', false);
});

it('points Bluesky server failures at the server field', function () {
    Http::fake([
        'https://pds.example/xrpc/*' => Http::response('<html>nope</html>', 200),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'at')
        ->set('customServer', true)
        ->set('instance', 'pds.example')
        ->set('username', 'alice')
        ->set('password', 'abcd-efgh-ijkl-mnop')
        ->call('loginBluesky')
        ->assertSet('errorField', 'instance')
        ->assertSet('errorMessage', sprintf(__('messages.error.not_bluesky'), 'pds.example'));
});

it('accepts a full Mastodon handle as the instance', function () {
    Http::fake([
        'https://fosstodon.org/api/v1/apps' => Http::response(['client_id' => 'cid', 'client_secret' => 'secret']),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'mastodon')
        ->set('instance', '@bob@fosstodon.org')
        ->call('startMastodon')
        ->assertRedirectContains('https://fosstodon.org/oauth/authorize');
});

it('explains when the Mastodon instance is not a Mastodon server', function () {
    Http::fake([
        'https://example.com/api/v1/apps' => Http::response('Not found', 404),
    ]);

    Livewire::test(LoginForm::class)
        ->call('selectNetwork', 'mastodon')
        ->set('instance', 'example.com')
        ->call('startMastodon')
        ->assertNoRedirect()
        ->assertSet('errorMessage', sprintf(__('messages.error.not_mastodon'), 'example.com'));
});

it('explains a denied Mastodon authorization', function () {
    $this->withSession([
        'mastodon_state' => 'state-123',
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'cid',
        'mastodon_client_secret' => 'secret',
    ])->get('/auth/mastodon/callback?error=access_denied&state=state-123')
        ->assertRedirect('/')
        ->assertSessionHas('flash', __('messages.error.oauth_denied'))
        ->assertSessionHas('flash_instance', 'mastodon.social');

    $this->assertGuest();
});

it('shows logout as a neutral notice, not an error', function () {
    $this->actingAs(User::factory()->create())->post('/logout')
        ->assertSessionHas('flash_notice', __('messages.auth.logged_out'))
        ->assertSessionMissing('flash');
});

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

it('saves the grid preferences', function () {
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(['user' => ['name' => 'alice']]),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->assertSet('grid_source', User::GRID_ARTIST)
        ->assertSet('grid_size', 5)
        ->set('grid_source', User::GRID_ALBUM)
        ->set('grid_size', 9)
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->grid_source)->toBe(User::GRID_ALBUM)
        ->and($user->grid_size)->toBe(9);
});

it('rejects unsupported grid preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->set('grid_source', 'track')
        ->set('grid_size', 7)
        ->call('save')
        ->assertHasErrors(['grid_source', 'grid_size']);

    expect($user->fresh()->grid_size)->toBe(5);
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

it('shows the schedule in the user timezone, not in UTC', function () {
    // Friday 22:00 in São Paulo is stored as Saturday 01:00 UTC.
    [$dow, $time] = User::convertLocalScheduleToUtc(5, '22:00', new DateTimeZone('America/Sao_Paulo'));
    $user = User::factory()->scheduled()->create(['day_of_week' => $dow, 'time' => $time, 'timezone' => 'America/Sao_Paulo']);

    expect($dow)->toBe(6);

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->assertSet('editing', false)
        ->assertSet('day_of_week', 5)
        ->assertSet('hour', '22:00')
        ->assertSee(__('messages.settings.title'));

    expect($user->nextPostAt()->getTimezone()->getName())->toBe('America/Sao_Paulo')
        ->and($user->nextPostAt()->format('N H:i'))->toBe('5 22:00');
});

it('computes the next post strictly in the future', function () {
    $user = User::factory()->make(['day_of_week' => 5, 'time' => '12:00:00', 'timezone' => 'UTC']);

    // Friday 2026-11-13 12:00 UTC exactly → the following Friday.
    expect($user->nextPostAt(new DateTimeImmutable('2026-11-13 12:00:00', new DateTimeZone('UTC')))->toDateString())->toBe('2026-11-20')
        ->and($user->nextPostAt(new DateTimeImmutable('2026-11-13 11:59:00', new DateTimeZone('UTC')))->toDateString())->toBe('2026-11-13');
});

it('cancels editing and restores the stored values', function () {
    $user = User::factory()->scheduled()->create(['lastfm_username' => 'alice']);

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->call('edit')
        ->set('lastfm_username', 'someone-else')
        ->call('cancel')
        ->assertSet('editing', false)
        ->assertSet('lastfm_username', 'alice');
});

it('explains when Last.fm cannot be reached', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response('down', 503)]);
    $user = User::factory()->create(['lastfm_username' => null]);

    Livewire::actingAs($user)
        ->test(SettingsForm::class)
        ->set('lastfm_username', 'alice')
        ->set('day_of_week', 5)
        ->set('hour', '09:00')
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertHasErrors(['lastfm_username'])
        ->assertSee(__('messages.error.lastfm_unreachable'));
});

it('shows the next post date once scheduled', function () {
    $user = User::factory()->scheduled()->create();

    $this->actingAs($user)->get('/settings')
        ->assertOk()
        ->assertSee('status--success', false)
        ->assertSee(__('messages.settings.page_title').' · ', false);
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
