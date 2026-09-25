<?php

declare(strict_types=1);

use App\Services\MontageService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

beforeEach(function () {
    Storage::fake('montage');
    $this->tmp = [];
});

afterEach(function () {
    foreach ($this->tmp as $file) {
        @unlink($file);
    }
});

it('builds a montage from an animated gif using only the first frame', function () {
    $gif = ImageManager::gd()->animate(function ($animation) {
        foreach (['#ff0000', '#00ff00', '#0000ff'] as $color) {
            $animation->add(ImageManager::gd()->create(200, 200)->fill($color), 0.1);
        }
    })->toGif();

    $path = tempnam(sys_get_temp_dir(), 'gif');
    $this->tmp[] = $path;
    file_put_contents($path, (string) $gif);

    $url = (new MontageService)->createWeeklyMontage(42, [$path]);

    expect($url)->toBe('/montage/'.md5('42'));
    Storage::disk('montage')->assertExists(md5('42').'.jpg');
});

it('falls back to a placeholder for unreadable image files', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad');
    $this->tmp[] = $path;
    file_put_contents($path, 'not an image');

    (new MontageService)->createWeeklyMontage(7, [$path]);

    Storage::disk('montage')->assertExists(md5('7').'.jpg');
});
