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
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => null]);

    $this->images = app(ArtistImageFetcher::class);

    $this->jpeg = (string) ImageManager::gd()->create(600, 600)->fill('#123456')->toJpeg(quality: 100);
    expect(strlen($this->jpeg))->toBeGreaterThan(2048);
});

function fakeArtistPage(string $html, ?string $imageBody = null): void
{
    Http::fake([
        'https://www.last.fm/music/*' => Http::response($html),
        'https://img.test/*' => Http::response($imageBody ?? '', 200),
    ]);
}

it('builds the Last.fm artist URL with + instead of %20', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->pathFor('Arctic Monkeys');

    Http::assertSent(fn ($r) => $r->url() === 'https://www.last.fm/music/Arctic+Monkeys');
});

it('percent-encodes non-ascii artist names', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->pathFor('Sigur Rós');

    Http::assertSent(fn ($r) => $r->url() === 'https://www.last.fm/music/Sigur+R%C3%B3s');
});

it('reads the image url from og:image:secure_url when og:image is absent', function () {
    fakeArtistPage(
        '<html><head><meta property="og:image:secure_url" content="https://img.test/secure.jpg"></head>',
        $this->jpeg
    );

    expect($this->images->pathFor('Band'))->not->toBe('');
    Http::assertSent(fn ($r) => $r->url() === 'https://img.test/secure.jpg');
});

it('returns an empty path when the artist page has no og:image', function () {
    fakeArtistPage('<html><head><title>none</title></head></html>');

    expect($this->images->pathFor('Band'))->toBe('');
});

it('returns an empty path when the artist page is a 404', function () {
    Http::fake(['https://www.last.fm/music/*' => Http::response('not found', 404)]);

    expect($this->images->pathFor('Band'))->toBe('');
});

it('caches the downloaded image under md5 of the lowercased name', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $hash = md5('band');
    $path = $this->images->pathFor('Band');

    expect($path)->toBe(Storage::disk('artist-cache')->path('').'/'.$hash.'.jpg')
        ->and(is_file($path))->toBeTrue()
        ->and(Artist::where('name', 'Band')->first()->image_hash)->toBe($hash);
});

it('treats a sub-2KB download as a placeholder and stores the sentinel hash', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', str_repeat('x', 1024));

    expect($this->images->pathFor('Tiny'))->toBe(public_path('images/placeholder.jpg'))
        ->and(Artist::where('name', 'Tiny')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('serves the cached file without hitting the network on a second call', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->pathFor('Band');
    $pageCalls = count(Http::recorded(fn ($r) => str_contains($r->url(), 'last.fm/music')));

    $this->images->pathFor('Band');

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'last.fm/music')))->toHaveCount($pageCalls);
});

it('serves the placeholder file for artists flagged as placeholder', function () {
    $artist = Artist::factory()->create(['image_hash' => Artist::PLACEHOLDER_HASH]);

    expect($this->images->cachedPath($artist))->toBe(public_path('images/placeholder.jpg'));
});

it('returns null from the cache lookup when nothing was downloaded', function () {
    $artist = Artist::factory()->create(['image_hash' => null]);

    expect($this->images->cachedPath($artist))->toBeNull();
});

it('backfills image_hash when a cached file exists but the column is empty', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => null]);
    Storage::disk('artist-cache')->put(md5('band').'.jpg', $this->jpeg);

    expect($this->images->cachedPath($artist))->not->toBeNull()
        ->and($artist->refresh()->image_hash)->toBe(md5('band'));
});

it('downloads an artist image from an explicit url', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => null]);

    Http::fake(['https://img.test/*' => Http::response($this->jpeg, 200)]);

    expect($this->images->fromUrl((int) $artist->id, 'https://img.test/cover.jpg'))->toBeTrue()
        ->and($artist->refresh()->image_hash)->toBe(md5('band'));

    Storage::disk('artist-cache')->assertExists(md5('band').'.jpg');
});

it('reports failure when the explicit url cannot be downloaded', function () {
    $artist = Artist::factory()->create(['name' => 'Band']);

    Http::fake(['https://img.test/*' => Http::response('nope', 404)]);

    expect($this->images->fromUrl((int) $artist->id, 'https://img.test/cover.jpg'))->toBeFalse();
});

it('overwrites the cached file when regenerating an artist image', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => md5('band')]);
    Storage::disk('artist-cache')->put(md5('band').'.jpg', 'stale');

    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    expect($this->images->regenerate((int) $artist->id))->toBeTrue()
        ->and(Storage::disk('artist-cache')->get(md5('band').'.jpg'))->toBe($this->jpeg);
});

it('reports failure when regenerating an unknown artist', function () {
    expect($this->images->regenerate(999))->toBeFalse();
});
