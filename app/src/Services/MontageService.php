<?php

declare(strict_types=1);

namespace App\Services;

use Intervention\Image\ImageManager;
use Psr\Log\LoggerInterface;

final class MontageService
{
    private string $montageDir;
    private ImageManager $images;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $basePath,
    ) {
        $this->montageDir = $basePath . '/data/montage';
        if (!is_dir($this->montageDir)) {
            mkdir($this->montageDir, 0775, true);
        }
        $this->images = ImageManager::gd();
    }

    /**
     * @param list<string> $imagePaths absolute paths (may include empty strings)
     * @return string relative path (from project root)
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

        $relative = 'data/montage/' . md5((string) $userId) . '.jpg';
        $abs = dirname(__DIR__, 2) . '/' . $relative;

        $canvas->toJpeg(quality: 82)->save($abs);
        return $relative;
    }

    /**
     * Place an image (or placeholder) on the canvas at specified position
     */
    private function placeImage(
        \Intervention\Image\Interfaces\ImageInterface $canvas,
        string $path,
        int $x,
        int $y,
        int $width,
        int $height
    ): void {
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

