<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Log\LoggerInterface;

final class CryptoService
{
    private const CIPHER = 'aes-256-cbc';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = (string) ($_ENV['ENCRYPTION_KEY'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY is not configured');
        }

        $ivlen = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivlen);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('openssl_encrypt failed');
        }

        $hmac = hash_hmac('sha256', $ciphertext, $key, true);
        return base64_encode($iv . $hmac . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $key = (string) ($_ENV['ENCRYPTION_KEY'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('ENCRYPTION_KEY is not configured');
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid encrypted payload: base64 decode failed');
        }

        $ivlen = openssl_cipher_iv_length(self::CIPHER);

        $iv = substr($raw, 0, $ivlen);
        $hmac = substr($raw, $ivlen, 32);
        $ciphertext = substr($raw, $ivlen + 32);

        $calcmac = hash_hmac('sha256', $ciphertext, $key, true);
        if (!hash_equals($hmac, $calcmac)) {
            $this->logger->warning('HMAC mismatch on decrypt');
            throw new \RuntimeException('Integrity check failed');
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('openssl_decrypt failed');
        }

        return $plain;
    }
}
