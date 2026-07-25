<?php

declare(strict_types=1);

use App\Models\Artist;
use App\Services\LastFm\ArtistImageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('artist-cache');
    config([
        'lastfm.api_key' => 'test-api-key',
        'lastfm.proxy_url' => null,
        'lastfm.images.backfill_per_tick' => 5,
        'lastfm.images.placeholder_retry_days' => 30,
    ]);

    $this->images = app(ArtistImageFetcher::class);
    $this->jpeg = (string) ImageManager::gd()->create(600, 600)->fill('#334455')->toJpeg(quality: 100);
});

function fakePageWithImage(string $jpeg): void
{
    Http::fake([
        'https://www.last.fm/music/*' => Http::response('<meta property="og:image" content="https://img.test/a.jpg">'),
        'https://img.test/*' => Http::response($jpeg, 200),
    ]);
}

it('backfills artists that never got an image', function () {
    Artist::factory()->create(['name' => 'A', 'image_hash' => null]);
    Artist::factory()->create(['name' => 'B', 'image_hash' => '']);
    Artist::factory()->create(['name' => 'Done', 'image_hash' => md5('done')]);

    fakePageWithImage($this->jpeg);

    expect($this->images->backfill())->toBe(['attempted' => 2, 'succeeded' => 2]);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'music/Done'));
});

it('honours the per-tick slice size', function () {
    Artist::factory()->count(4)->sequence(
        ['name' => 'A'], ['name' => 'B'], ['name' => 'C'], ['name' => 'D'],
    )->create(['image_hash' => null]);

    fakePageWithImage($this->jpeg);

    expect($this->images->backfill(2)['attempted'])->toBe(2);
});

it('does nothing when the slice size is zero', function () {
    Artist::factory()->create(['image_hash' => null]);

    expect($this->images->backfill(0))->toBe(['attempted' => 0, 'succeeded' => 0]);

    Http::assertNothingSent();
});

it('skips placeholders that are still fresh', function () {
    Artist::factory()->create([
        'name' => 'Fresh',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(5),
    ]);

    expect($this->images->backfill())->toBe(['attempted' => 0, 'succeeded' => 0]);

    Http::assertNothingSent();
});

it('retries placeholders older than the retry window', function () {
    Artist::factory()->create([
        'name' => 'Stale',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(31),
    ]);

    fakePageWithImage($this->jpeg);

    expect($this->images->backfill())->toBe(['attempted' => 1, 'succeeded' => 1]);
    expect(Artist::where('name', 'Stale')->first()->image_hash)->toBe(md5('stale'));
});

it('restarts the retry window when a stale placeholder is still a placeholder', function () {
    $artist = Artist::factory()->create([
        'name' => 'Stale',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(31),
    ]);

    fakePageWithImage(str_repeat('x', 512));

    $this->images->backfill();

    expect($artist->refresh()->image_hash)->toBe(Artist::PLACEHOLDER_HASH)
        ->and($artist->updated_at->isToday())->toBeTrue();

    // Second tick must not pick it up again.
    Http::fake();
    expect($this->images->backfill())->toBe(['attempted' => 0, 'succeeded' => 0]);
});

it('rotates past artists that keep failing instead of retrying them forever', function () {
    $stuck = Artist::factory()->create(['name' => 'Stuck', 'image_hash' => null, 'updated_at' => now()->subDays(2)]);
    Artist::factory()->create(['name' => 'Next', 'image_hash' => null, 'updated_at' => now()->subDay()]);

    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    // Oldest attempt first: "Stuck" goes first and its timestamp is bumped.
    expect($this->images->backfill(1)['attempted'])->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'music/Stuck'));
    expect($stuck->refresh()->updated_at->isToday())->toBeTrue();

    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    expect($this->images->backfill(1)['attempted'])->toBe(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'music/Next'));
});

it('serves the placeholder when an expired retry fails during chart processing', function () {
    $artist = Artist::factory()->create([
        'name' => 'Stale',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(31),
    ]);

    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    expect($this->images->pathFor('Stale'))->toBe(public_path('images/placeholder.jpg'))
        ->and($artist->refresh()->updated_at->isToday())->toBeTrue();
});

it('does not re-scrape a fresh placeholder during chart processing', function () {
    Artist::factory()->create([
        'name' => 'Fresh',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDay(),
    ]);

    expect($this->images->pathFor('Fresh'))->toBe(public_path('images/placeholder.jpg'));

    Http::assertNothingSent();
});
