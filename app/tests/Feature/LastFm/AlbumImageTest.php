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

it('re-downloads a cover on regenerate, replacing a placeholder', function () {
    $album = Album::create(['artist_name' => 'Radiohead', 'name' => 'OK Computer', 'image_hash' => Album::PLACEHOLDER_HASH]);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(albumInfo('https://img.test/cover.jpg')),
        'https://img.test/*' => Http::response($this->cover),
    ]);

    expect($this->images->regenerate($album))->toBeTrue()
        ->and($album->refresh()->image_hash)->toBe(md5('radiohead|ok computer'))
        ->and(getimagesizefromstring($this->images->cachedImage($album))['mime'])->toBe('image/webp');
});

it('sets a cover from a URL and keeps the current one when it fails', function () {
    $album = Album::create(['artist_name' => 'Radiohead', 'name' => 'Kid A']);

    Http::fake(['https://img.test/good.jpg' => Http::response($this->cover), 'https://img.test/bad.jpg' => Http::response('', 404)]);

    expect($this->images->fromUrl($album, 'https://img.test/good.jpg'))->toBeTrue();
    $stored = $this->images->cachedImage($album);

    expect($this->images->fromUrl($album, 'https://img.test/bad.jpg'))->toBeFalse()
        ->and($this->images->cachedImage($album))->toBe($stored)
        ->and($album->refresh()->image_hash)->toBe(md5('radiohead|kid a'));
});

it('downloads the original size cover from the Last.fm CDN', function () {
    $big = (string) ImageManager::gd()->create(1200, 1200)->fill('#123456')->toJpeg(quality: 100);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(albumInfo('https://lastfm.cdn.test/i/u/300x300/abc.png')),
        'https://lastfm.cdn.test/i/u/abc.png' => Http::response($big),
        'https://lastfm.cdn.test/i/u/300x300/abc.png' => Http::response($this->cover),
    ]);

    $info = getimagesizefromstring($this->images->imageFor('Radiohead', 'Kid A'));

    expect([$info[0], $info[1]])->toBe([600, 600]);
    Http::assertSent(fn ($r) => $r->url() === 'https://lastfm.cdn.test/i/u/abc.png');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '300x300/abc.png'));
});

it('falls back to the 300px cover when the original is unavailable', function () {
    $small = (string) ImageManager::gd()->create(300, 300)->fill('#654321')->toJpeg(quality: 100);

    Http::fake([
        'https://ws.audioscrobbler.com/*' => Http::response(albumInfo('https://lastfm.cdn.test/i/u/300x300/abc.png')),
        'https://lastfm.cdn.test/i/u/abc.png' => Http::response('', 404),
        'https://lastfm.cdn.test/i/u/300x300/abc.png' => Http::response($small),
    ]);

    $info = getimagesizefromstring($this->images->imageFor('Radiohead', 'Amnesiac'));

    expect($info[0])->toBe(300)
        ->and(Album::where('name', 'Amnesiac')->first()->image_hash)->toBe(md5('radiohead|amnesiac'));
});

it('builds the original size url only for sized Last.fm CDN urls', function (string $url, ?string $expected) {
    expect(AlbumImageFetcher::originalSizeUrl($url))->toBe($expected);
})->with([
    ['https://lastfm.freetls.fastly.net/i/u/300x300/abc.png', 'https://lastfm.freetls.fastly.net/i/u/abc.png'],
    ['https://lastfm.freetls.fastly.net/i/u/174s/abc.png', 'https://lastfm.freetls.fastly.net/i/u/abc.png'],
    ['https://lastfm.freetls.fastly.net/i/u/abc.png', null],
    ['https://img.test/cover.jpg', null],
]);
