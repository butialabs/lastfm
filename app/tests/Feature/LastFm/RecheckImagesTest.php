<?php

declare(strict_types=1);

use App\Models\Album;
use App\Models\Artist;
use App\Services\LastFm\ArtistImageScraper;
use App\Services\LastFm\ProxiedHttp;
use App\Services\LastFm\RequestPolicy;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use App\Services\LastFm\RequestPolicy;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Intervention\Image\ImageManager;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('artist-cache');
    Storage::fake('album-cache');
    Sleep::fake();
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => 'http://proxy.test:8080']);

    $this->jpeg = (string) ImageManager::gd()->create(600, 600)->fill('#123456')->toJpeg(quality: 100);
});

it('never falls back to a direct request under the strict policy', function () {
    config(['lastfm.proxy_url' => null]);

    $http = (new ProxiedHttp)->withPolicy(RequestPolicy::strict(3, 0, 0));

    expect(fn () => $http->get('https://www.last.fm/', [], fn () => true, 'test'))
        ->toThrow(RuntimeException::class);
});

it('builds proxy options that Guzzle\'s cURL handler accepts', function (string $builder, array $args) {
    $scraper = app(ArtistImageScraper::class);
    $base = (fn () => $this->{$builder}(...$args))->call($scraper);

    $options = (new ProxiedHttp)->requestOptions($base, 'https://user:pass@proxy.test:8443');

    expect($options)->not->toHaveKey('curl')
        ->and($options['proxy'])->toBe('https://user:pass@proxy.test:8443');

    $factory = new CurlFactory(1);
    $easy = $factory->create(new GuzzleRequest('GET', 'https://www.last.fm/music/Test'), $options);
    $factory->release($easy);
    $factory->close();
})->with([
    'artist page' => ['browserPageOptions', ['Test']],
    'image binary' => ['imageBinaryOptions', []],
]);

it('makes exactly N proxy attempts and pauses before each one', function () {
    Http::fake(['*' => Http::response('blocked', 429)]);

    $http = (new ProxiedHttp)->withPolicy(RequestPolicy::strict(4, 5, 60));
    $res = $http->get('https://www.last.fm/music/X', [], fn (Response $r) => $r->successful(), 'test');

    expect($res)->toBeNull();
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(4);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 5 && $duration->totalSeconds <= 60, 4);
});

it('keeps the default policy: two proxy attempts, then direct, no pause', function () {
    Http::fake(['*' => Http::response('blocked', 429)]);

    (new ProxiedHttp)->get('https://www.last.fm/music/X', [], fn (Response $r) => $r->successful(), 'test');

    Http::assertSentCount(3);
    Sleep::assertNeverSlept();
});

it('picks among several configured proxies', function () {
    config(['lastfm.proxy_url' => 'http://a.test:1, http://b.test:2']);

    expect((new ProxiedHttp)->proxyUrls())->toBe(['http://a.test:1', 'http://b.test:2']);
});

it('refuses to run without a proxy', function () {
    config(['lastfm.proxy_url' => null]);

    $this->artisan('lastfm:images:recheck')->assertFailed();
});

it('lists placeholder records on a dry run without any request', function () {
    Http::fake();
    Artist::factory()->create(['name' => 'Stuck', 'image_hash' => Artist::PLACEHOLDER_HASH]);
    Artist::factory()->create(['name' => 'Never', 'image_hash' => null]);
    Album::factory()->create(['artist_name' => 'Stuck', 'name' => 'Demo', 'image_hash' => Album::PLACEHOLDER_HASH]);

    expect(Artisan::call('lastfm:images:recheck', ['--dry-run' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('Artists: 1')
        ->toContain('Stuck — Demo')
        ->not->toContain('Never');

    Http::assertNothingSent();
});

it('recovers placeholder artists through the proxy only', function () {
    $stuck = Artist::factory()->create(['name' => 'Stuck', 'image_hash' => Artist::PLACEHOLDER_HASH]);
    $blocked = Artist::factory()->create(['name' => 'Blocked', 'image_hash' => Artist::PLACEHOLDER_HASH]);

    Http::fake([
        'https://www.last.fm/music/Stuck' => Http::response('<meta property="og:image" content="https://img.test/a.jpg">'),
        'https://www.last.fm/music/Blocked' => Http::response('blocked', 403),
        'https://img.test/*' => Http::response($this->jpeg),
    ]);

    $this->artisan('lastfm:images:recheck', ['type' => 'artists', '--attempts' => 2])
        ->expectsOutputToContain('recovered')
        ->expectsOutputToContain('failed')
        ->assertSuccessful();

    expect($stuck->refresh()->image_hash)->toBe(md5('stuck'))
        ->and($blocked->refresh()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);

    // Stuck: page + image; Blocked: 2 attempts on the page.
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(4);
});

it('includes never-fetched records with --missing and honours --id', function () {
    $never = Artist::factory()->create(['name' => 'Never', 'image_hash' => null]);
    $other = Artist::factory()->create(['name' => 'Other', 'image_hash' => null]);

    Http::fake([
        'https://www.last.fm/music/*' => Http::response('<meta property="og:image" content="https://img.test/a.jpg">'),
        'https://img.test/*' => Http::response($this->jpeg),
    ]);

    $this->artisan('lastfm:images:recheck', ['type' => 'artists', '--missing' => true, '--id' => [$never->id]])
        ->assertSuccessful();

    expect($never->refresh()->image_hash)->toBe(md5('never'))
        ->and($other->refresh()->image_hash)->toBeNull();
});
