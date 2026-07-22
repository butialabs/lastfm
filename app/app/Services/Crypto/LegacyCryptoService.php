<?php

declare(strict_types=1);

namespace App\Services\Crypto;

use Illuminate\Support\Facades\Log;

/**
 * Bit-for-bit replica of the legacy (pre-Laravel) CryptoService:
 * aes-256-cbc + HMAC-SHA256 (encrypt-then-MAC), raw ENCRYPTION_KEY.
 * Payload: base64( IV(16) ‖ HMAC(32) ‖ ciphertext ).
 *
 * Only lastfm:import-legacy uses this class. Everything else uses
 * Laravel's Crypt (APP_KEY).
 */
final class LegacyCryptoService
{
    private const CIPHER = 'aes-256-cbc';

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();

        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('openssl_encrypt failed');
        }

        $hmac = hash_hmac('sha256', $ciphertext, $key, true);

        return base64_encode($iv.$hmac.$ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $key = $this->key();

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid encrypted payload: base64 decode failed');
        }

        $ivlen = openssl_cipher_iv_length(self::CIPHER);

        $iv = substr($raw, 0, $ivlen);
        $hmac = substr($raw, $ivlen, 32);
        $ciphertext = substr($raw, $ivlen + 32);

        $calcmac = hash_hmac('sha256', $ciphertext, $key, true);
        if (! hash_equals($hmac, $calcmac)) {
            Log::channel('app')->warning('LegacyCrypto: HMAC mismatch on decrypt');

            throw new \RuntimeException('Integrity check failed');
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('openssl_decrypt failed');
        }

        return $plain;
    }

    private function key(): string
    {
        $key = (string) config('lastfm.encryption_key', '');

        if ($key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY is not configured');
        }

        return $key;
    }
}
