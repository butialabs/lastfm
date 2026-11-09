<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

final class MontageService
{
    /** Grid sizes a user can pick. */
    public const SIZES = [3, 4, 5, 6, 9];

    public const DEFAULT_SIZE = 5;

    // Stay under Bluesky's ~1MB blob limit.
    private const MAX_BYTES = 950_000;

    private const QUALITIES = [82, 70, 60];

    /**
     * Canvas size and cells ([x, y, w, h] in pixels) per grid size.
     *
     * @var array<int, array{0:int, 1:int, 2:list<array{0:int,1:int,2:int,3:int}>}>
     */
    private const LAYOUTS = [
        // 1 large on the left + 2 stacked.
        3 => [1200, 600, [[0, 0, 600, 600], [600, 0, 600, 300], [600, 300, 600, 300]]],
        // 2x2.
        4 => [1200, 1200, [[0, 0, 600, 600], [600, 0, 600, 600], [0, 600, 600, 600], [600, 600, 600, 600]]],
        // 1 large on the left + 2x2 (the original layout).
        5 => [1200, 600, [[0, 0, 600, 600], [600, 0, 300, 300], [900, 0, 300, 300], [600, 300, 300, 300], [900, 300, 300, 300]]],
        // 3x2.
        6 => [1200, 800, [
            [0, 0, 400, 400], [400, 0, 400, 400], [800, 0, 400, 400],
            [0, 400, 400, 400], [400, 400, 400, 400], [800, 400, 400, 400],
        ]],
        // 3x3.
        9 => [1200, 1200, [
            [0, 0, 400, 400], [400, 0, 400, 400], [800, 0, 400, 400],
            [0, 400, 400, 400], [400, 400, 400, 400], [800, 400, 400, 400],
            [0, 800, 400, 400], [400, 800, 400, 400], [800, 800, 400, 400],
        ]],
    ];

    private ImageManager $images;

    public function __construct()
    {
        $this->images = ImageManager::gd(decodeAnimation: false);
    }

    public static function normalizeSize(?int $size): int
    {
        return in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
    }

    /**
     * @param  list<?string>  $images  image binaries, in chart order (null/'' = no image)
     * @return string montage URL (e.g. /montage/{hash})
     */
    public function createWeeklyMontage(int $userId, array $images, int $size = self::DEFAULT_SIZE): string
    {
        [$canvasW, $canvasH, $cells] = self::LAYOUTS[self::normalizeSize($size)];

        $images = array_values($images);
        $canvas = $this->images->create($canvasW, $canvasH)->fill('#0b1020');

        foreach ($cells as $i => [$x, $y, $w, $h]) {
            $this->placeImage($canvas, $images[$i] ?? null, $x, $y, $w, $h);
        }

        // Filename = md5(user_id), keeping existing public URLs valid.
        $hash = md5((string) $userId);

        if (! Storage::disk('montage')->put($hash.'.jpg', $this->encode($canvas))) {
            throw new \RuntimeException('Unable to write the montage');
        }

        return '/montage/'.$hash;
    }

    private function encode(ImageInterface $canvas): string
    {
        foreach (self::QUALITIES as $quality) {
            $jpeg = (string) $canvas->toJpeg(quality: $quality);

            if (strlen($jpeg) <= self::MAX_BYTES) {
                break;
            }
        }

        return $jpeg;
    }

    private function placeImage(ImageInterface $canvas, ?string $bin, int $x, int $y, int $width, int $height): void
    {
        $img = null;

        if ($bin !== null && $bin !== '' && $this->fitsInMemory($bin)) {
            try {
                $img = $this->images->read($bin)->cover($width, $height);
            } catch (\Throwable) {
                $img = null;
            }
        }

        $canvas->place($img ?? $this->images->create($width, $height)->fill('#243b55'), 'top-left', $x, $y);
    }

    private function fitsInMemory(string $bin): bool
    {
        $size = @getimagesizefromstring($bin);

        return $size !== false && $size[0] * $size[1] <= 25_000_000;
    }
}
