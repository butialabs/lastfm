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
    // Same guard MontageService used: refuse decompression bombs.
    private const MAX_PIXELS = 25_000_000;

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

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('Image too large to decode');
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
