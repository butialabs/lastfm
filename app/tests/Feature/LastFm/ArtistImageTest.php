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

    $this->placeholder = file_get_contents(public_path('images/placeholder.jpg'));
});

function isNormalizedWebp(?string $bin, int $max = 600): bool
{
    $info = $bin !== null ? getimagesizefromstring($bin) : false;

    return $info !== false && $info['mime'] === 'image/webp' && $info[0] === $info[1] && $info[0] <= $max;
}

function fakeArtistPage(string $html, ?string $imageBody = null): void
{
    Http::fake([
        'https://www.last.fm/music/*' => Http::response($html),
        'https://img.test/*' => Http::response($imageBody ?? '', 200),
    ]);
}

it('builds the Last.fm artist URL with + instead of %20', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->imageFor('Arctic Monkeys');

    Http::assertSent(fn ($r) => $r->url() === 'https://www.last.fm/music/Arctic+Monkeys');
});

it('percent-encodes non-ascii artist names', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->imageFor('Sigur Rós');

    Http::assertSent(fn ($r) => $r->url() === 'https://www.last.fm/music/Sigur+R%C3%B3s');
});

it('reads the image url from og:image:secure_url when og:image is absent', function () {
    fakeArtistPage(
        '<html><head><meta property="og:image:secure_url" content="https://img.test/secure.jpg"></head>',
        $this->jpeg
    );

    expect($this->images->imageFor('Band'))->not->toBeNull()->not->toBe($this->placeholder);
    Http::assertSent(fn ($r) => $r->url() === 'https://img.test/secure.jpg');
});

it('caches the downloaded image under md5 of the lowercased name', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $hash = md5('band');
    $bin = $this->images->imageFor('Band');

    Storage::disk('artist-cache')->assertExists($hash.'.webp');
    expect($bin)->toBe(Storage::disk('artist-cache')->get($hash.'.webp'))
        ->and(Artist::where('name', 'Band')->first()->image_hash)->toBe($hash);
});

it('normalizes downloads to a square webp of at most 600px', function () {
    $wide = (string) ImageManager::gd()->create(1600, 900)->fill('#336699')->toPng();
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.png">', $wide);

    $bin = $this->images->imageFor('Wide');

    expect(isNormalizedWebp($bin))->toBeTrue()
        ->and(getimagesizefromstring($bin)[0])->toBe(600)
        ->and(strlen($bin))->toBeLessThan(strlen($wide));
});

it('does not upscale images smaller than the target size', function () {
    // Random noise, so the small image is not mistaken for a sub-2KB placeholder.
    $gd = imagecreatetruecolor(300, 200);
    for ($i = 0; $i < 2000; $i++) {
        imagesetpixel($gd, random_int(0, 299), random_int(0, 199), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($gd, null, 100);
    $small = (string) ob_get_clean();
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $small);

    expect(getimagesizefromstring($this->images->imageFor('Small'))[0])->toBe(200);
});

it('treats an undecodable download as a failed download', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', str_repeat('garbage', 1000));

    expect($this->images->imageFor('Garbage'))->toBe($this->placeholder)
        ->and(Artist::where('name', 'Garbage')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
    expect(Storage::disk('artist-cache')->allFiles())->toBe([]);
});

it('converts a legacy jpg cache entry to webp on first use', function () {
    Artist::factory()->create(['name' => 'Band', 'image_hash' => md5('band')]);
    Storage::disk('artist-cache')->put(md5('band').'.jpg', $this->jpeg);
    Http::fake();

    expect(isNormalizedWebp($this->images->imageFor('Band')))->toBeTrue();

    Storage::disk('artist-cache')->assertExists(md5('band').'.webp');
    Storage::disk('artist-cache')->assertMissing(md5('band').'.jpg');
    Http::assertNothingSent();
});

it('treats a sub-2KB download as a placeholder and stores the sentinel hash', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', str_repeat('x', 1024));

    expect($this->images->imageFor('Tiny'))->toBe($this->placeholder)
        ->and(Artist::where('name', 'Tiny')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('serves the cached file without hitting the network on a second call', function () {
    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->imageFor('Band');
    $pageCalls = count(Http::recorded(fn ($r) => str_contains($r->url(), 'last.fm/music')));

    $this->images->imageFor('Band');

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'last.fm/music')))->toHaveCount($pageCalls);
});

it('serves the placeholder file for artists flagged as placeholder', function () {
    $artist = Artist::factory()->create(['image_hash' => Artist::PLACEHOLDER_HASH]);

    expect($this->images->cachedImage($artist))->toBe($this->placeholder);
});

it('returns null from the cache lookup when nothing was downloaded', function () {
    $artist = Artist::factory()->create(['image_hash' => null]);

    expect($this->images->cachedImage($artist))->toBeNull();
});

it('backfills image_hash when a cached file exists but the column is empty', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => null]);
    Storage::disk('artist-cache')->put(md5('band').'.jpg', $this->jpeg);

    expect($this->images->cachedImage($artist))->not->toBeNull()
        ->and($artist->refresh()->image_hash)->toBe(md5('band'));
});

it('downloads an artist image from an explicit url', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => null]);

    Http::fake(['https://img.test/*' => Http::response($this->jpeg, 200)]);

    expect($this->images->fromUrl((int) $artist->id, 'https://img.test/cover.jpg'))->toBeTrue()
        ->and($artist->refresh()->image_hash)->toBe(md5('band'));

    Storage::disk('artist-cache')->assertExists(md5('band').'.webp');
});

it('reports failure when the explicit url cannot be downloaded', function () {
    $artist = Artist::factory()->create(['name' => 'Band']);

    Http::fake(['https://img.test/*' => Http::response('nope', 404)]);

    expect($this->images->fromUrl((int) $artist->id, 'https://img.test/cover.jpg'))->toBeFalse()
        ->and($artist->refresh()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('overwrites the cached file when regenerating an artist image', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => md5('band')]);
    Storage::disk('artist-cache')->put(md5('band').'.webp', 'stale');

    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    expect($this->images->regenerate((int) $artist->id))->toBeTrue()
        ->and(isNormalizedWebp(Storage::disk('artist-cache')->get(md5('band').'.webp')))->toBeTrue();
});

it('reports failure when regenerating an unknown artist', function () {
    expect($this->images->regenerate(999))->toBeFalse();
});

it('stores the placeholder when the artist page cannot be found', function () {
    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    expect($this->images->imageFor('Unknown'))->toBe($this->placeholder)
        ->and(Artist::where('name', 'Unknown')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('stores the placeholder when the page has no image', function () {
    fakeArtistPage('<html><head></head></html>');

    expect($this->images->imageFor('NoImage'))->toBe($this->placeholder)
        ->and(Artist::where('name', 'NoImage')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('stores the placeholder when the image binary cannot be downloaded', function () {
    Http::fake([
        'https://www.last.fm/music/*' => Http::response('<meta property="og:image" content="https://img.test/a.jpg">'),
        'https://img.test/*' => Http::response('', 500),
    ]);

    expect($this->images->imageFor('Broken'))->toBe($this->placeholder)
        ->and(Artist::where('name', 'Broken')->first()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});

it('does not re-scrape a failed artist while the placeholder is fresh', function () {
    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);
    $this->images->imageFor('Unknown');

    Http::fake();

    expect($this->images->imageFor('Unknown'))->toBe($this->placeholder);
    Http::assertNothingSent();
});

it('restarts the retry window when an expired placeholder fails again', function () {
    $artist = Artist::factory()->create([
        'name' => 'Stale',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(31),
    ]);

    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    expect($this->images->imageFor('Stale'))->toBe($this->placeholder)
        ->and($artist->refresh()->image_hash)->toBe(Artist::PLACEHOLDER_HASH)
        ->and($artist->updated_at->isToday())->toBeTrue();
});

it('replaces an expired placeholder when the retry succeeds', function () {
    $artist = Artist::factory()->create([
        'name' => 'Stale',
        'image_hash' => Artist::PLACEHOLDER_HASH,
        'updated_at' => now()->subDays(31),
    ]);

    fakeArtistPage('<meta property="og:image" content="https://img.test/a.jpg">', $this->jpeg);

    $this->images->imageFor('Stale');

    expect($artist->refresh()->image_hash)->toBe(md5('stale'));
});

it('stores the placeholder when regenerating fails', function () {
    $artist = Artist::factory()->create(['name' => 'Band', 'image_hash' => md5('band')]);
    Storage::disk('artist-cache')->put(md5('band').'.jpg', $this->jpeg);

    Http::fake(['https://www.last.fm/music/*' => Http::response('gone', 404)]);

    expect($this->images->regenerate((int) $artist->id))->toBeFalse()
        ->and($artist->refresh()->image_hash)->toBe(Artist::PLACEHOLDER_HASH);
});
