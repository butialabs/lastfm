<?php

declare(strict_types=1);

use App\Models\Artist;
use App\Models\ArtistStat;
use App\Models\User;
use App\Services\LastFm\LastFmApi;
use App\Services\LastFm\WeeklyChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['lastfm.api_key' => 'test-api-key', 'lastfm.proxy_url' => null]);

    $this->api = app(LastFmApi::class);
    $this->charts = app(WeeklyChartService::class);
});

function fakeChart(array $artists): void
{
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(
        ['weeklyartistchart' => ['artist' => $artists]]
    )]);
}

it('validates an existing Last.fm user', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(['user' => ['name' => 'alice']])]);

    expect($this->api->userExists('alice'))->toBeTrue();
});

it('treats a Last.fm API error as an invalid user', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response(['error' => 6, 'message' => 'User not found'])]);

    expect($this->api->userExists('nobody'))->toBeFalse();
});

it('propagates transport failures instead of reporting an invalid user', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response('gateway down', 502)]);

    $this->api->userExists('alice');
})->throws(RuntimeException::class, 'Last.fm request failed');

it('fails clearly when the API key is missing', function () {
    config(['lastfm.api_key' => '']);

    $this->api->userExists('alice');
})->throws(RuntimeException::class, 'LASTFM_API is not configured');

it('totals scrobbles across the whole chart, not just the returned slice', function () {
    fakeChart([
        ['name' => 'A', 'playcount' => '10', 'mbid' => '', 'image' => []],
        ['name' => 'B', 'playcount' => '7', 'mbid' => '', 'image' => []],
        ['name' => 'C', 'playcount' => '3', 'mbid' => '', 'image' => []],
        ['name' => 'D', 'playcount' => '1', 'mbid' => '', 'image' => []],
    ]);

    $chart = $this->charts->forUser('alice', 2);

    expect($chart['artists'])->toHaveCount(2)
        ->and($chart['artists'][0]['name'])->toBe('A')
        ->and($chart['total_scrobbles'])->toBe(21);
});

it('accepts a chart with a single artist returned as an object', function () {
    fakeChart(['name' => 'Solo', 'playcount' => '5', 'mbid' => '', 'image' => []]);

    $chart = $this->charts->forUser('alice');

    expect($chart['artists'])->toHaveCount(1)
        ->and($chart['total_scrobbles'])->toBe(5);
});

it('defaults the chart size to the configured value', function () {
    config(['lastfm.post.chart_size' => 2]);

    fakeChart([
        ['name' => 'A', 'playcount' => '3', 'mbid' => '', 'image' => []],
        ['name' => 'B', 'playcount' => '2', 'mbid' => '', 'image' => []],
        ['name' => 'C', 'playcount' => '1', 'mbid' => '', 'image' => []],
    ]);

    expect($this->charts->forUser('alice')['artists'])->toHaveCount(2);
});

it('records chart positions in order and backfills a missing musicbrainz id', function () {
    $existing = Artist::factory()->create(['name' => 'B', 'musicbrainz_id' => null]);
    $user = User::factory()->create();

    fakeChart([
        ['name' => 'A', 'playcount' => '10', 'mbid' => 'mbid-a', 'image' => []],
        ['name' => 'B', 'playcount' => '7', 'mbid' => 'mbid-b', 'image' => []],
    ]);

    $this->charts->forUser('alice', 5, (int) $user->id);

    expect($existing->refresh()->musicbrainz_id)->toBe('mbid-b')
        ->and(Artist::where('name', 'A')->first()->musicbrainz_id)->toBe('mbid-a');

    $stats = ArtistStat::where('user_id', $user->id)->orderBy('position')->get();
    expect($stats->pluck('position')->all())->toBe([1, 2])
        ->and($stats->pluck('play_count')->all())->toBe([10, 7]);
});

it('does not duplicate an artist that is already known', function () {
    Artist::factory()->create(['name' => 'A']);

    fakeChart([['name' => 'A', 'playcount' => '10', 'mbid' => '', 'image' => []]]);

    $this->charts->forUser('alice');

    expect(Artist::where('name', 'A')->count())->toBe(1);
});

it('records no stats when no user is given', function () {
    fakeChart([['name' => 'A', 'playcount' => '10', 'mbid' => '', 'image' => []]]);

    $this->charts->forUser('alice');

    expect(ArtistStat::count())->toBe(0);
});

it('tries the proxy twice before falling back to a direct request', function () {
    config(['lastfm.proxy_url' => 'http://proxy.test:8080']);

    Http::fake(['https://ws.audioscrobbler.com/*' => Http::sequence()
        ->push('boom', 500)
        ->push('boom', 500)
        ->push(['user' => ['name' => 'alice']], 200),
    ]);

    expect($this->api->userExists('alice'))->toBeTrue();

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'audioscrobbler')))->toHaveCount(3);
});

it('makes a single attempt when no proxy is configured', function () {
    Http::fake(['https://ws.audioscrobbler.com/*' => Http::response('boom', 500)]);

    try {
        $this->api->userExists('alice');
    } catch (RuntimeException) {
        // expected
    }

    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'audioscrobbler')))->toHaveCount(1);
});
