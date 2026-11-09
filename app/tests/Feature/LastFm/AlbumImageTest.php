<?php

declare(strict_types=1);

use App\Models\Album;
use App\Services\LastFm\AlbumImageFetcher;
use App\Services\LastFm\LastFmApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('album-cache');
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => null]);

    $this->images = app(AlbumImageFetcher::class);
    $this->cover = (string) ImageManager::gd()->create(1000, 1000)->fill('#654321')->toJpeg(quality: 100);
    $this->placeholder = file_get_contents(public_path('images/placeholder.jpg'));
});

function albumInfo(?string $url): array
{
    return ['album' => ['name' => 'OK Computer', 'image' => [
        ['#text' => '', 'size' => 'small'],
        ['#text' => $url ?? '', 'size' => 'extralarge'],
    ]]];
}

it('downloads the album cover from album.getinfo and caches it as webp', function () {
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(albumInfo('https://img.test/cover.jpg')),
        'https://img.test/*' => Http::response($this->cover),
    ]);

    $bin = $this->images->imageFor('Radiohead', 'OK Computer');
    $hash = md5('radiohead|ok computer');

    $info = getimagesizefromstring($bin);
    expect($info['mime'])->toBe('image/webp')
        ->and($info[0])->toBe(600)
        ->and(Album::where('name', 'OK Computer')->first()->image_hash)->toBe($hash);

    Storage::disk('album-cache')->assertExists($hash.'.webp');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'method=album.getinfo')
        && str_contains($r->url(), 'artist=Radiohead'));
});

it('serves the cache without hitting the network on a second call', function () {
    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(albumInfo('https://img.test/cover.jpg')),
        'https://img.test/*' => Http::response($this->cover),
    ]);
    $this->images->imageFor('Radiohead', 'OK Computer');

    Http::fake();
    expect($this->images->imageFor('Radiohead', 'OK Computer'))->not->toBe($this->placeholder);
    Http::assertNothingSent();
});

it('stores the placeholder when the album has no cover', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(albumInfo(null))]);

    expect($this->images->imageFor('Someone', 'Demo'))->toBe($this->placeholder)
        ->and(Album::where('name', 'Demo')->first()->image_hash)->toBe(Album::PLACEHOLDER_HASH);

    Http::fake();
    $this->images->imageFor('Someone', 'Demo');
    Http::assertNothingSent();
});

it('stores the placeholder when the album is unknown to Last.fm', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(['error' => 6, 'message' => 'Album not found'])]);

    expect($this->images->imageFor('Someone', 'Ghost'))->toBe($this->placeholder);
});

it('retries album.getinfo by name when the mbid is unknown', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::sequence()
        ->push(['error' => 6, 'message' => 'Album not found'])
        ->push(albumInfo('https://img.test/c.jpg')),
    ]);

    expect(app(LastFmApi::class)->albumImageUrl('Radiohead', 'OK Computer', 'bad-mbid'))->toBe('https://img.test/c.jpg');
});

it('parses the weekly album chart, including the single-object form', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(['weeklyalbumchart' => ['album' => [
        'name' => 'OK Computer', 'playcount' => '12', 'mbid' => '', 'artist' => ['#text' => 'Radiohead', 'mbid' => ''],
    ]]])]);

    expect(app(LastFmApi::class)->weeklyAlbumChart('alice', 5))->toBe([
        'albums' => [['name' => 'OK Computer', 'artist' => 'Radiohead', 'playcount' => 12, 'mbid' => null]],
        'total_scrobbles' => 12,
    ]);
});
