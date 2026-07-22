<?php

declare(strict_types=1);

use App\Models\Artist;
use App\Models\ArtistStat;
use App\Models\User;
use App\Services\Processors\QueueProcessor;
use App\Services\Processors\UserProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('montage');
    Storage::fake('artist-cache');
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => null]);

    // JPEG real (>2KB) para passar pelas validações de imagem do fluxo.
    $this->jpegBinary = (string) ImageManager::gd()
        ->create(300, 300)
        ->fill('#a4666a')
        ->toJpeg(quality: 90);

    expect(strlen($this->jpegBinary))->toBeGreaterThan(2048);

    $this->weeklyChart = [
        'weeklyartistchart' => [
            'artist' => [
                ['name' => 'Band One', 'playcount' => '30', 'mbid' => 'mbid-1', 'image' => []],
                ['name' => 'Band Two', 'playcount' => '20', 'mbid' => '', 'image' => []],
            ],
        ],
    ];
});

function makeQueuedUser(string $jpegBinary, array $overrides = []): User
{
    $user = User::factory()->queued()->create(array_merge([
        'password' => Crypt::encryptString('app-password'),
        'language' => 'en',
    ], $overrides));

    Storage::disk('montage')->put(md5((string) $user->id).'.jpg', $jpegBinary);

    return $user;
}

/*
|--------------------------------------------------------------------------
| UserProcessor (schedule → montagem → QUEUED)
|--------------------------------------------------------------------------
*/

it('processes a scheduled user: records stats, builds montage and queues', function () {
    $user = User::factory()->scheduled()->create(['lastfm_username' => 'alice']);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response($this->weeklyChart),
        'https://www.last.fm/music/*' => Http::response('<meta property="og:image" content="https://images.example/img.jpg">'),
        'https://images.example/*' => Http::response($this->jpegBinary, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $processor = app(UserProcessor::class);
    expect($processor->processUser($user))->toBeTrue();

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_QUEUED)
        ->and($user->social_montage)->toBe('/montage/'.md5((string) $user->id))
        ->and($user->callback)->toBe('Queued successfully');

    // Montage stored on disk as md5(user_id)
    Storage::disk('montage')->assertExists(md5((string) $user->id).'.jpg');

    // Stats recorded for the 2 chart artists
    expect(Artist::where('name', 'Band One')->exists())->toBeTrue();
    expect(ArtistStat::where('user_id', $user->id)->count())->toBe(2);
});

it('marks error with SCHEDULE retry status when the chart fails', function () {
    $user = User::factory()->scheduled()->create(['lastfm_username' => 'alice']);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response('Server Error', 500),
    ]);

    $processor = app(UserProcessor::class);
    expect($processor->processUser($user))->toBeFalse();

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_SCHEDULE)
        ->and($user->error_count)->toBe(1);
});

it('selects users due for schedule at the exact UTC minute', function () {
    // Now: Friday (5) 12:30 UTC
    $now = Carbon\CarbonImmutable::parse('2026-07-24 12:30:00', 'UTC');

    $due = User::factory()->scheduled()->create([
        'lastfm_username' => 'alice',
        'day_of_week' => 5,
        'time' => '12:30:00',
        'timezone' => 'America/Sao_Paulo',
    ]);
    User::factory()->scheduled()->create(['day_of_week' => 5, 'time' => '12:31:00']); // another minute
    User::factory()->scheduled()->create(['day_of_week' => 4, 'time' => '12:30:00']); // another day
    User::factory()->create(['status' => User::STATUS_ACTIVE, 'day_of_week' => 5, 'time' => '12:30:00']); // wrong status

    $result = User::dueForSchedule($now);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($due->id);
});

/*
|--------------------------------------------------------------------------
| QueueProcessor (QUEUED → envio → SCHEDULE)
|--------------------------------------------------------------------------
*/

it('sends to Bluesky as a threaded post with the montage embed', function () {
    $user = makeQueuedUser($this->jpegBinary);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response($this->weeklyChart),
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:alice',
            'accessJwt' => 'jwt',
            'refreshJwt' => 'refresh',
        ]),
        'https://bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiabc'], 'mimeType' => 'image/jpeg', 'size' => 12345],
        ]),
        'https://bsky.social/xrpc/com.atproto.identity.resolveHandle' => Http::response(['did' => 'did:plc:bot']),
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:alice/app.bsky.feed.post/1', 'cid' => 'cid1']),
    ]);

    $processor = app(QueueProcessor::class);
    expect($processor->sendForUser($user))->toBeTrue();

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_SCHEDULE)
        ->and($user->social_message)->toContain('Band One (30)')
        ->and($user->social_message)->toContain('#myweekcounted')
        ->and($user->social_message)->toContain('50 Scrobbles') // 30 + 20, placeholder %d preenchido
        ->and($user->error_count)->toBe(0)
        ->and($user->callback)->toBe('Sent successfully');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'uploadBlob'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        && $request['record']['embed']['$type'] === 'app.bsky.embed.images'
        && str_contains($request['record']['embed']['images'][0]['alt'], 'Band One'));
});

it('sends to Mastodon with media attached to the first status', function () {
    $user = makeQueuedUser($this->jpegBinary, [
        'protocol' => User::PROTOCOL_MASTODON,
        'instance' => 'https://mastodon.social',
        'username' => 'bob',
        'password' => null,
        'token' => Crypt::encryptString('mastodon-token'),
    ]);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response($this->weeklyChart),
        'https://mastodon.social/api/v2/media' => Http::response(['id' => 'media-1']),
        'https://mastodon.social/api/v1/statuses' => Http::response(['id' => 'status-1']),
    ]);

    $processor = app(QueueProcessor::class);
    expect($processor->sendForUser($user))->toBeTrue();

    $user->refresh();
    expect($user->status)->toBe(User::STATUS_SCHEDULE);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/media'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/statuses'));
});

it('requeues on temporary failure and gives up after MAX_ERROR_COUNT', function () {
    config(['lastfm.max_error_count' => 3]);

    $user = makeQueuedUser($this->jpegBinary);

    // createSession always fails (401 with no valid JSON)
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response($this->weeklyChart),
        'https://bsky.social/xrpc/*' => Http::response(['error' => 'AuthenticationRequired'], 401),
    ]);

    $processor = app(QueueProcessor::class);

    // Failures 1 and 2 → back to QUEUED
    expect($processor->sendForUser($user))->toBeFalse();
    expect($user->refresh()->status)->toBe(User::STATUS_QUEUED)
        ->and($user->error_count)->toBe(1);

    expect($processor->sendForUser($user))->toBeFalse();
    expect($user->refresh()->status)->toBe(User::STATUS_QUEUED)
        ->and($user->error_count)->toBe(2);

    // Failure 3 → gives up until next week (SCHEDULE)
    expect($processor->sendForUser($user))->toBeFalse();
    expect($user->refresh()->status)->toBe(User::STATUS_SCHEDULE)
        ->and($user->error_count)->toBe(3)
        ->and($user->callback)->toContain('Giving up until next week');
});

it('fails when the montage file is missing', function () {
    $user = User::factory()->queued()->create([
        'password' => Crypt::encryptString('app-password'),
        'social_montage' => '/montage/'.md5('inexistente'),
    ]);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response($this->weeklyChart),
    ]);

    $processor = app(QueueProcessor::class);
    expect($processor->sendForUser($user))->toBeFalse();

    expect($user->refresh()->error_count)->toBe(1);
});
