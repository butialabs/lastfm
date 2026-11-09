<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

beforeEach(function () {
    foreach (['montage', 'artist-cache', 'album-cache', 'montage-local', 'artist-cache-local', 'album-cache-local'] as $disk) {
        Storage::fake($disk);
    }

    $this->jpeg = (string) ImageManager::gd()->create(900, 700)->fill('#123456')->toJpeg(quality: 100);
});

function configureS3Disks(): void
{
    // The fakes replace the actual drivers; only the configured driver matters.
    foreach (['montage', 'artist-cache', 'album-cache'] as $disk) {
        config(["filesystems.disks.{$disk}.driver" => 's3']);
    }
}

it('copies local images to s3 untouched', function () {
    configureS3Disks();

    Storage::disk('montage-local')->put(md5('1').'.jpg', $this->jpeg);
    Storage::disk('artist-cache-local')->put(md5('band').'.jpg', $this->jpeg);
    Storage::disk('artist-cache-local')->put('notes.txt', 'ignored');

    $this->artisan('lastfm:files', ['action' => 's3'])->assertSuccessful();

    expect(Storage::disk('montage')->get(md5('1').'.jpg'))->toBe($this->jpeg)
        ->and(Storage::disk('artist-cache')->get(md5('band').'.jpg'))->toBe($this->jpeg);

    Storage::disk('artist-cache')->assertMissing('notes.txt');
    Storage::disk('artist-cache-local')->assertExists(md5('band').'.jpg');
});

it('deletes the local copies with --delete-source', function () {
    configureS3Disks();

    Storage::disk('montage-local')->put(md5('1').'.jpg', $this->jpeg);

    $this->artisan('lastfm:files', ['action' => 's3', '--delete-source' => true])->assertSuccessful();

    Storage::disk('montage')->assertExists(md5('1').'.jpg');
    Storage::disk('montage-local')->assertMissing(md5('1').'.jpg');
});

it('refuses to copy to s3 when IMAGE_STORAGE is local', function () {
    Storage::disk('montage-local')->put(md5('1').'.jpg', $this->jpeg);

    $this->artisan('lastfm:files', ['action' => 's3', '--delete-source' => true])
        ->expectsOutputToContain('IMAGE_STORAGE is not "s3"')
        ->assertFailed();

    Storage::disk('montage-local')->assertExists(md5('1').'.jpg');
});

it('normalizes the cache in place on the configured disks', function () {
    Storage::disk('artist-cache')->put(md5('band').'.jpg', $this->jpeg);
    Storage::disk('album-cache')->put(md5('a|b').'.png', (string) ImageManager::gd()->create(800, 800)->fill('#00ff00')->toPng());
    Storage::disk('montage')->put(md5('1').'.jpg', $this->jpeg);

    $this->artisan('lastfm:files', ['action' => 'normalize-cache'])->assertSuccessful();

    $info = getimagesizefromstring(Storage::disk('artist-cache')->get(md5('band').'.webp'));
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe([600, 600]);

    Storage::disk('artist-cache')->assertMissing(md5('band').'.jpg');
    Storage::disk('album-cache')->assertExists(md5('a|b').'.webp');
    Storage::disk('album-cache')->assertMissing(md5('a|b').'.png');

    // Montages are not part of the cache.
    expect(Storage::disk('montage')->get(md5('1').'.jpg'))->toBe($this->jpeg);
});

it('skips entries that are already normalized', function () {
    Storage::disk('artist-cache')->put(md5('band').'.webp', 'already-normalized');

    $this->artisan('lastfm:files', ['action' => 'normalize-cache'])->assertSuccessful();

    expect(Storage::disk('artist-cache')->get(md5('band').'.webp'))->toBe('already-normalized');
});

it('keeps and reports cache entries that cannot be normalized', function () {
    Storage::disk('artist-cache')->put(md5('broken').'.jpg', 'not an image');

    $this->artisan('lastfm:files', ['action' => 'normalize-cache'])
        ->expectsOutputToContain('could not be normalized')
        ->assertSuccessful();

    Storage::disk('artist-cache')->assertExists(md5('broken').'.jpg');
});

it('rejects unknown actions', function () {
    $this->artisan('lastfm:files', ['action' => 'nope'])
        ->expectsOutputToContain('Use: s3 | normalize-cache')
        ->assertExitCode(2);
});
