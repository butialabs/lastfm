<?php

declare(strict_types=1);

use App\Services\Images\ImageNormalizer;

function jpegOfSize(int $width, int $height): string
{
    $gd = imagecreatetruecolor($width, $height);
    imagefill($gd, 0, 0, imagecolorallocate($gd, 200, 40, 90));
    ob_start();
    imagejpeg($gd, null, 80);
    imagedestroy($gd);

    return (string) ob_get_clean();
}

it('normalizes images above the old 25 MP limit when memory allows', function () {
    $original = ini_set('memory_limit', '512M');

    try {
        $bin = (new ImageNormalizer)->normalize(jpegOfSize(6000, 5000)); // 30 MP
    } finally {
        ini_set('memory_limit', (string) $original);
    }

    $info = getimagesizefromstring($bin);
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe([600, 600]);
});

it('derives the pixel limit from the free memory', function () {
    $normalizer = new ImageNormalizer;
    $original = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '-1');
        expect($normalizer->maxPixels())->toBe(100_000_000);

        ini_set('memory_limit', (string) (memory_get_usage(true) + 50 * 1024 * 1024)); // ~50MB free
        expect($normalizer->maxPixels())->toBeLessThan(8_000_000)->toBeGreaterThan(5_000_000);
    } finally {
        ini_set('memory_limit', (string) $original);
    }
});

it('reports the dimensions and limit when an image is too large', function () {
    $bin = jpegOfSize(4000, 3000); // 12 MP
    $original = ini_get('memory_limit');

    try {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 20 * 1024 * 1024));
        (new ImageNormalizer)->normalize($bin);
    } finally {
        ini_set('memory_limit', (string) $original);
    }
})->throws(RuntimeException::class, 'Image too large to decode: 4000x3000 (12.0 MP');
