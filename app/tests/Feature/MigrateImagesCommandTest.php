<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

beforeEach(function () {
    foreach (['montage', 'artist-cache', 'album-cache'] as $disk) {
        config(["filesystems.disks.{$disk}.driver" => 's3']);
    }

    foreach (['montage', 'artist-cache', 'album-cache', 'montage-local', 'artist-cache-local', 'album-cache-local'] as $disk) {
        Storage::fake($disk);
    }

    $this->jpeg = (string) ImageManager::gd()->create(900, 700)->fill('#123456')->toJpeg(quality: 100);
});

it('copies local images to the configured disks, normalizing the caches', function () {
    Storage::disk('montage-local')->put(md5('1').'.jpg', $this->jpeg);
    Storage::disk('artist-cache-local')->put(md5('band').'.jpg', $this->jpeg);
    Storage::disk('artist-cache-local')->put('notes.txt', 'ignored');

    $this->artisan('images:migrate', ['--normalize' => true, '--delete-source' => true])->assertSuccessful();

    expect(Storage::disk('montage')->get(md5('1').'.jpg'))->toBe($this->jpeg);

    $info = getimagesizefromstring(Storage::disk('artist-cache')->get(md5('band').'.webp'));
    expect($info['mime'])->toBe('image/webp')
        ->and($info[0])->toBe(600);

    Storage::disk('artist-cache-local')->assertMissing(md5('band').'.jpg');
    Storage::disk('artist-cache')->assertMissing('notes.txt');
});

it('keeps the source and reports files that cannot be normalized', function () {
    Storage::disk('artist-cache-local')->put(md5('broken').'.jpg', 'not an image');

    $this->artisan('images:migrate', ['--normalize' => true, '--delete-source' => true])
        ->expectsOutputToContain('could not be migrated')
        ->assertSuccessful();

    Storage::disk('artist-cache-local')->assertExists(md5('broken').'.jpg');
});

it('normalizes the local cache in place when IMAGE_STORAGE is local', function () {
    foreach (['montage', 'artist-cache', 'album-cache'] as $disk) {
        config(["filesystems.disks.{$disk}.driver" => 'local']);
    }

    Storage::disk('montage-local')->put(md5('1').'.jpg', $this->jpeg);
    Storage::disk('artist-cache-local')->put(md5('band').'.jpg', $this->jpeg);

    $this->artisan('images:migrate', ['--normalize' => true])
        ->expectsOutputToContain('montage: already on local storage')
        ->assertSuccessful();

    Storage::disk('artist-cache')->assertExists(md5('band').'.webp');
    Storage::disk('artist-cache-local')->assertMissing(md5('band').'.jpg');
    Storage::disk('montage')->assertMissing(md5('1').'.jpg');
});
