<?php

declare(strict_types=1);

use App\Services\Crypto\LegacyCryptoService;

beforeEach(function () {
    config(['lastfm.encryption_key' => 'fixture-key-32-chars-000000000000']);
    $this->crypto = new LegacyCryptoService;
});

it('decrypts payloads produced by the legacy app', function () {
    // Fixtures generated with the original legacy CryptoService using the key above.
    $passwordPayload = 'rB+1P4SvxmvL+nzuNQbJjc1+zo23IpXrWZCuJ/ofWFWEdG9OgbNyOTAQNNOA9cwQDjJLosJawakiReFNsosJSi3/RTzlbfaPhoGRPtxLkbM=';
    $tokenPayload = 'eMEz9IKLCBuWjDhCPG1wXllf/Yess1K9QVTDNat9838RBDI7GIBocJ1GOR8YoyYnzp2/pCvTniPxwsjTh97MyDypsQ0ho4Cq4Sz4jadrL7o=';

    expect($this->crypto->decrypt($passwordPayload))->toBe('my-secret-app-password')
        ->and($this->crypto->decrypt($tokenPayload))->toBe('mastodon-oauth-token-xyz');
});

it('round-trips encrypt/decrypt in the legacy format', function () {
    $payload = $this->crypto->encrypt('senha com acentuação é ç ã');

    // Formato: base64( IV(16) ‖ HMAC(32) ‖ ciphertext )
    $raw = base64_decode($payload, true);
    expect($raw)->not->toBeFalse()
        ->and(strlen($raw))->toBeGreaterThan(48);

    expect($this->crypto->decrypt($payload))->toBe('senha com acentuação é ç ã');
});

it('rejects tampered payloads (HMAC mismatch)', function () {
    $payload = $this->crypto->encrypt('secret');

    $raw = base64_decode($payload, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';
    $tampered = base64_encode($raw);

    $this->crypto->decrypt($tampered);
})->throws(RuntimeException::class, 'Integrity check failed');

it('rejects invalid base64', function () {
    $this->crypto->decrypt('!!!not-base64!!!');
})->throws(RuntimeException::class, 'base64 decode failed');

it('throws when ENCRYPTION_KEY is missing', function () {
    config(['lastfm.encryption_key' => '']);

    (new LegacyCryptoService)->decrypt('whatever');
})->throws(RuntimeException::class, 'ENCRYPTION_KEY is not configured');
