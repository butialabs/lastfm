<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

final class MontageService
{
    private ImageManager $images;

    public function __construct()
    {
        $this->images = ImageManager::gd();
    }

    /**
     * @param  list<string>  $imagePaths  absolute paths (may contain empty strings)
     * @return string montage URL (e.g. /montage/{hash})
     */
    public function createWeeklyMontage(int $userId, array $imagePaths): string
    {
        $imagePaths = array_values(array_filter($imagePaths, static fn ($p) => is_string($p) && $p !== ''));

        $canvasW = 1200;
        $canvasH = 600;

        $canvas = $this->images->create($canvasW, $canvasH)->fill('#0b1020');

        $leftW = (int) floor($canvasW / 2);
        $rightCellW = (int) floor($canvasW / 4);
        $rightCellH = (int) floor($canvasH / 2);

        $this->placeImage($canvas, $imagePaths[0] ?? '', 0, 0, $leftW, $canvasH);
        $this->placeImage($canvas, $imagePaths[1] ?? '', $leftW, 0, $rightCellW, $rightCellH);
        $this->placeImage($canvas, $imagePaths[2] ?? '', $leftW + $rightCellW, 0, $rightCellW, $rightCellH);
        $this->placeImage($canvas, $imagePaths[3] ?? '', $leftW, $rightCellH, $rightCellW, $rightCellH);
        $this->placeImage($canvas, $imagePaths[4] ?? '', $leftW + $rightCellW, $rightCellH, $rightCellW, $rightCellH);

        // Filename = md5(user_id), keeping existing public URLs valid.
        $hash = md5((string) $userId);

        $dir = Storage::disk('montage')->path('');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $canvas->toJpeg(quality: 82)->save(Storage::disk('montage')->path($hash.'.jpg'));

        return '/montage/'.$hash;
    }

    private function placeImage(ImageInterface $canvas, string $path, int $x, int $y, int $width, int $height): void
    {
        if ($path !== '' && is_file($path)) {
            $img = $this->images->read($path);
            $img = $img->cover($width, $height);
            $canvas->place($img, 'top-left', $x, $y);
        } else {
            $block = $this->images->create($width, $height)->fill('#243b55');
            $canvas->place($block, 'top-left', $x, $y);
        }
    }
}
