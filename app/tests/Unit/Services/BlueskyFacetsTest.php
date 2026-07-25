<?php

declare(strict_types=1);

use App\Services\Social\BlueskyClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->client = new BlueskyClient;
});

function fakeHandleResolution(?string $did): void
{
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.identity.resolveHandle*' => $did === null
            ? Http::response(['error' => 'NotFound'], 400)
            : Http::response(['did' => $did]),
    ]);
}

/**
 * Bluesky indexes facets by BYTE offset. PHP's preg_match_all with /u and
 * PREG_OFFSET_CAPTURE already reports byte offsets, so these tests fail loudly
 * if anyone converts the arithmetic to mb_* functions.
 */
function facetSlice(string $text, array $facet): string
{
    return substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']);
}

it('points hashtag facets at the right bytes after multibyte artist names', function () {
    $text = '♫ Top: Björk (30) Sigur Rós (20). #myweekcounted #music';

    $facets = $this->client->parseFacets('https://bsky.social', $text);
    $tags = array_values(array_filter(
        $facets,
        fn ($f) => $f['features'][0]['$type'] === 'app.bsky.richtext.facet#tag'
    ));

    expect($tags)->toHaveCount(2)
        ->and(facetSlice($text, $tags[0]))->toBe('#myweekcounted')
        ->and(facetSlice($text, $tags[1]))->toBe('#music')
        ->and($tags[0]['features'][0]['tag'])->toBe('myweekcounted');
});

it('points mention facets at the right bytes after multibyte artist names', function () {
    fakeHandleResolution('did:plc:bot');

    $text = '♫ 椎名林檎 (42) via @lastfm-butialabs.bsky.social';

    $facets = $this->client->parseFacets('https://bsky.social', $text);
    $mentions = array_values(array_filter(
        $facets,
        fn ($f) => $f['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
    ));

    expect($mentions)->toHaveCount(1)
        ->and(facetSlice($text, $mentions[0]))->toBe('@lastfm-butialabs.bsky.social')
        ->and($mentions[0]['features'][0]['did'])->toBe('did:plc:bot');
});

it('points link facets at the right bytes after multibyte artist names', function () {
    $text = 'Motörhead & Sigur Rós → https://last.fm/user/alice done';

    $facets = $this->client->parseFacets('https://bsky.social', $text);
    $links = array_values(array_filter(
        $facets,
        fn ($f) => $f['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
    ));

    expect($links)->toHaveCount(1)
        ->and(facetSlice($text, $links[0]))->toBe('https://last.fm/user/alice')
        ->and($links[0]['features'][0]['uri'])->toBe('https://last.fm/user/alice');
});

it('drops mentions that cannot be resolved to a DID', function () {
    fakeHandleResolution(null);

    expect($this->client->parseFacets('https://bsky.social', 'hi @ghost.bsky.social'))->toBe([]);
});

it('returns no facets for plain text', function () {
    expect($this->client->parseFacets('https://bsky.social', 'Björk (30) Sigur Rós (20)'))->toBe([]);
});
