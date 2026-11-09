<?php

declare(strict_types=1);

namespace App\Services\Images;

use Intervention\Image\ImageManager;

/**
 * Standardizes downloaded images before they are cached: square center crop
 * (never upscaled), re-encoded without metadata. Keeps the cache small and
 * every montage cell decoding from the same predictable input.
 */
final class ImageNormalizer
{
    private const MAX_PIXELS = 100_000_000;

    private const BYTES_PER_PIXEL = 5;

    private const MEMORY_SHARE = 0.7;

    private ImageManager $images;

    public function __construct()
    {
        $this->images = ImageManager::gd(decodeAnimation: false);
    }

    /**
     * @throws \RuntimeException when the binary is not a decodable image
     */
    public function normalize(string $bin): string
    {
        $info = @getimagesizefromstring($bin);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new \RuntimeException('Not a decodable image');
        }

        $pixels = $info[0] * $info[1];
        $limit = $this->maxPixels();

        if ($pixels > $limit) {
            throw new \RuntimeException(sprintf(
                'Image too large to decode: %dx%d (%.1f MP, limit %.1f MP with memory_limit=%s)',
                $info[0], $info[1], $pixels / 1e6, $limit / 1e6, ini_get('memory_limit'),
            ));
        }

        try {
            $img = $this->images->read($bin);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Not a decodable image: '.$e->getMessage(), 0, $e);
        }

        $target = min($this->size(), $img->width(), $img->height());
        $img = $img->cover($target, $target);

        $quality = (int) config('lastfm.images.quality', 80);

        return $this->format() === 'webp'
            ? (string) $img->toWebp(quality: $quality)
            : (string) $img->toJpeg(quality: $quality);
    }

    public function extension(): string
    {
        return $this->format() === 'webp' ? 'webp' : 'jpg';
    }

    public function mimeType(): string
    {
        return $this->format() === 'webp' ? 'image/webp' : 'image/jpeg';
    }

    public function maxPixels(): int
    {
        $limit = $this->memoryLimitBytes();

        if ($limit === null) {
            return self::MAX_PIXELS;
        }

        $free = max(0, $limit - memory_get_usage(true));

        return (int) min(self::MAX_PIXELS, $free * self::MEMORY_SHARE / self::BYTES_PER_PIXEL);
    }

    private function memoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1024 ** 3,
            'm' => $bytes * 1024 ** 2,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function size(): int
    {
        return max(64, (int) config('lastfm.images.size', 600));
    }

    private function format(): string
    {
        $format = strtolower((string) config('lastfm.images.format', 'webp'));

        return $format === 'webp' && function_exists('imagewebp') ? 'webp' : 'jpeg';
    }
}
