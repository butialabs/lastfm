<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageNormalizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Image file maintenance:
 *  - s3: copies the local image data (data/montage, data/cache/*) to the S3
 *    disks (requires IMAGE_STORAGE=s3).
 *  - normalize-cache: converts every artist/album cache entry on the
 *    configured disks (local or S3) to the standard format, in place.
 */
class FilesCommand extends Command
{
    protected $signature = 'lastfm:files
        {action : s3 | normalize-cache}
        {--delete-source : (s3) Delete each local file after it is copied}';

    protected $description = 'Manage image files: copy local images to S3 (s3) or normalize the image cache (normalize-cache)';

    /** local source disk => configured disk */
    private const S3_DISKS = [
        'montage-local' => 'montage',
        'artist-cache-local' => 'artist-cache',
        'album-cache-local' => 'album-cache',
    ];

    private const CACHE_DISKS = ['artist-cache', 'album-cache'];

    public function handle(ImageNormalizer $normalizer): int
    {
        return match ($this->argument('action')) {
            's3' => $this->copyToS3(),
            'normalize-cache' => $this->normalizeCache($normalizer),
            default => $this->invalidAction(),
        };
    }

    private function copyToS3(): int
    {
        if (config('filesystems.disks.montage.driver') !== 's3') {
            $this->error('IMAGE_STORAGE is not "s3": configure the bucket (IMAGE_STORAGE=s3, AWS_*) before copying.');

            return self::FAILURE;
        }

        $deleteSource = (bool) $this->option('delete-source');

        $failed = $this->eachImage(self::S3_DISKS, function (Filesystem $source, string $file, string $destName) use ($deleteSource): void {
            if (! Storage::disk($destName)->put($file, (string) $source->get($file))) {
                throw new \RuntimeException('write failed');
            }

            if ($deleteSource) {
                $source->delete($file);
            }
        });

        return $this->finish($failed, 'copied');
    }

    private function normalizeCache(ImageNormalizer $normalizer): int
    {
        $target = $normalizer->extension();

        $failed = $this->eachImage(array_combine(self::CACHE_DISKS, self::CACHE_DISKS), function (Filesystem $disk, string $file) use ($normalizer, $target): void {
            // Already normalized (by the fetchers or a previous run).
            if ($target === 'webp' && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'webp') {
                return;
            }

            $key = pathinfo($file, PATHINFO_FILENAME).'.'.$target;

            if (! $disk->put($key, $normalizer->normalize((string) $disk->get($file)))) {
                throw new \RuntimeException('write failed');
            }

            if ($key !== $file) {
                $disk->delete($file);
            }
        });

        return $this->finish($failed, 'normalized');
    }

    /**
     * Runs $action on every image of each source disk, with a progress bar.
     * Failures are reported and leave the file untouched.
     *
     * @param  array<string, string>  $disks  source disk => destination disk
     * @param  callable(Filesystem, string, string): void  $action
     */
    private function eachImage(array $disks, callable $action): int
    {
        $failed = 0;

        foreach ($disks as $sourceName => $destName) {
            $source = Storage::disk($sourceName);
            $files = array_values(array_filter($source->files(), fn (string $f) => $this->isImageKey($f)));

            $this->info("{$destName}: ".count($files).' file(s)');
            $bar = $this->output->createProgressBar(count($files));

            foreach ($files as $file) {
                try {
                    $action($source, $file, $destName);
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->warn("  {$file}: {$e->getMessage()}");
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        return $failed;
    }

    private function finish(int $failed, string $verb): int
    {
        if ($failed > 0) {
            $this->warn("{$failed} file(s) could not be {$verb} (left untouched).");
        }

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action "'.$this->argument('action').'". Use: s3 | normalize-cache');

        return self::INVALID;
    }

    private function isImageKey(string $file): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpe?g|png|gif|webp)$/i', $file) === 1;
    }
}
