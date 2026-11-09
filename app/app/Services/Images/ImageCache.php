<?php

declare(strict_types=1);

namespace App\Services\Images;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Image cache on a filesystem disk (local or S3): {hash}.{ext}, every entry
 * normalized by ImageNormalizer on write. Holds no database state.
 *
 * Entries written before normalization existed ({hash}.jpg, raw download) are
 * converted lazily the first time they are looked up.
 */
abstract class ImageCache
{
    // Any download smaller than 2KB is treated as a placeholder.
    private const PLACEHOLDER_MAX_BYTES = 2048;

    private const LEGACY_EXTENSION = 'jpg';

    public function __construct(protected readonly ImageNormalizer $normalizer) {}

    abstract protected function diskName(): string;

    public function hashFor(string ...$parts): string
    {
        return md5(strtolower(implode('|', array_map('trim', $parts))));
    }

    public function keyFor(string $hash): string
    {
        return $hash.'.'.$this->normalizer->extension();
    }

    public function has(string $hash): bool
    {
        return $this->disk()->exists($this->keyFor($hash)) || $this->upgradeLegacy($hash);
    }

    public function get(string $hash): ?string
    {
        if (! $this->has($hash)) {
            return null;
        }

        $bin = $this->disk()->get($this->keyFor($hash));

        return is_string($bin) && $bin !== '' ? $bin : null;
    }

    /**
     * Normalizes and stores a downloaded image.
     *
     * @throws \RuntimeException when the image cannot be decoded or written
     */
    public function put(string $hash, string $bin): void
    {
        $this->write($hash, $this->normalizer->normalize($bin));
    }

    public function forget(string $hash): void
    {
        $keys = array_unique([$this->keyFor($hash), $this->legacyKeyFor($hash)]);

        $this->disk()->delete($keys);
    }

    public function placeholderPath(): string
    {
        return public_path('images/placeholder.jpg');
    }

    public function placeholderBytes(): ?string
    {
        $path = $this->placeholderPath();
        $bin = is_file($path) ? file_get_contents($path) : false;

        return is_string($bin) && $bin !== '' ? $bin : null;
    }

    public function isPlaceholder(string $bin): bool
    {
        return strlen($bin) < self::PLACEHOLDER_MAX_BYTES;
    }

    protected function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function write(string $hash, string $normalized): void
    {
        if (! $this->disk()->put($this->keyFor($hash), $normalized)) {
            throw new \RuntimeException('Unable to write the cached image: '.$this->keyFor($hash));
        }
    }

    private function legacyKeyFor(string $hash): string
    {
        return $hash.'.'.self::LEGACY_EXTENSION;
    }

    private function upgradeLegacy(string $hash): bool
    {
        $legacy = $this->legacyKeyFor($hash);

        if ($legacy === $this->keyFor($hash) || ! $this->disk()->exists($legacy)) {
            return false;
        }

        try {
            $this->write($hash, $this->normalizer->normalize((string) $this->disk()->get($legacy)));
        } catch (\RuntimeException) {
            return false;
        }

        $this->disk()->delete($legacy);

        return true;
    }
}
