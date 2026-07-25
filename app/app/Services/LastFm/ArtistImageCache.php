<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use Illuminate\Support\Facades\Storage;

/**
 * Filesystem side of the artist image cache: {md5(lowercased name)}.jpg on the
 * "artist-cache" disk. Holds no database state.
 */
final class ArtistImageCache
{
    // Any image smaller than 2KB is treated as a placeholder.
    private const PLACEHOLDER_MAX_BYTES = 2048;

    public function hashFor(string $artistName): string
    {
        return md5(strtolower(trim($artistName)));
    }

    public function pathFor(string $hash): string
    {
        return $this->directory().'/'.$hash.'.jpg';
    }

    public function has(string $hash): bool
    {
        return is_file($this->pathFor($hash));
    }

    public function put(string $hash, string $bin): string
    {
        $path = $this->pathFor($hash);

        if (file_put_contents($path, $bin) !== strlen($bin)) {
            @unlink($path);

            throw new \RuntimeException('Unable to write the artist image: '.$path);
        }

        return $path;
    }

    public function forget(string $hash): void
    {
        $path = $this->pathFor($hash);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function placeholderPath(): string
    {
        return public_path('images/placeholder.jpg');
    }

    public function isPlaceholder(string $bin): bool
    {
        return strlen($bin) < self::PLACEHOLDER_MAX_BYTES;
    }

    private function directory(): string
    {
        $dir = Storage::disk('artist-cache')->path('');

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the artist image cache directory: '.$dir);
        }

        return $dir;
    }
}
