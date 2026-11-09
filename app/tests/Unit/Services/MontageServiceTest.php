<?php

declare(strict_types=1);

use App\Services\MontageService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

beforeEach(function () {
    Storage::fake('montage');
});

function montageSize(int $userId): array
{
    $info = getimagesizefromstring(Storage::disk('montage')->get(md5((string) $userId).'.jpg'));

    return [$info[0], $info[1], $info['mime']];
}

it('builds a montage from an animated gif using only the first frame', function () {
    $gif = ImageManager::gd()->animate(function ($animation) {
        foreach (['#ff0000', '#00ff00', '#0000ff'] as $color) {
            $animation->add(ImageManager::gd()->create(200, 200)->fill($color), 0.1);
        }
    })->toGif();

    $url = (new MontageService)->createWeeklyMontage(42, [(string) $gif]);

    expect($url)->toBe('/montage/'.md5('42'));
    Storage::disk('montage')->assertExists(md5('42').'.jpg');
});

it('falls back to a placeholder block for undecodable images', function () {
    (new MontageService)->createWeeklyMontage(7, ['not an image', null, '']);

    Storage::disk('montage')->assertExists(md5('7').'.jpg');
});

it('uses the canvas of each grid size', function (int $size, int $width, int $height) {
    $webp = (string) ImageManager::gd()->create(600, 600)->fill('#a4666a')->toWebp();

    (new MontageService)->createWeeklyMontage(1, array_fill(0, $size, $webp), $size);

    expect(montageSize(1))->toBe([$width, $height, 'image/jpeg']);
})->with([
    '3' => [3, 1200, 600],
    '4' => [4, 1200, 1200],
    '5' => [5, 1200, 600],
    '6' => [6, 1200, 800],
    '9' => [9, 1200, 1200],
]);

it('falls back to the default layout for unsupported sizes', function () {
    (new MontageService)->createWeeklyMontage(1, [], 7);

    expect(montageSize(1))->toBe([1200, 600, 'image/jpeg']);
});

it('places each image in its cell, in chart order', function () {
    $colors = ['#ff0000', '#00ff00', '#0000ff', '#ffff00'];
    $images = array_map(fn ($c) => (string) ImageManager::gd()->create(600, 600)->fill($c)->toPng(), $colors);

    (new MontageService)->createWeeklyMontage(1, $images, 4);

    $canvas = ImageManager::gd()->read(Storage::disk('montage')->get(md5('1').'.jpg'));

    // Center of each 2x2 cell.
    $centers = [[300, 300], [900, 300], [300, 900], [900, 900]];
    foreach ($centers as $i => [$x, $y]) {
        $rgb = $canvas->pickColor($x, $y)->toHex('#');
        $expected = $colors[$i];

        // JPEG is lossy: compare each channel with some tolerance.
        foreach ([1, 3, 5] as $offset) {
            expect(abs(hexdec(substr($rgb, $offset, 2)) - hexdec(substr($expected, $offset, 2))))->toBeLessThan(24);
        }
    }
});
