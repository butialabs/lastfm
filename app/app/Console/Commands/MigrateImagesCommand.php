<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Images\ImageNormalizer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Moves the local image data (data/montage, data/cache/*) to the configured
 * image disks (IMAGE_STORAGE), optionally normalizing cached images. With
 * IMAGE_STORAGE=local, --normalize converts the existing cache in place.
 */
class MigrateImagesCommand extends Command
{
    protected $signature = 'images:migrate
        {--normalize : Convert cached artist/album images to the standard format (WebP, square, resized)}
        {--delete-source : Delete each local file after it is copied to a remote disk}';

    protected $description = 'Copy local images to the configured image storage and/or normalize the image cache';

    /** source disk => [destination disk, normalizable] */
    private const DISKS = [
        'montage-local' => ['montage', false],
        'artist-cache-local' => ['artist-cache', true],
        'album-cache-local' => ['album-cache', true],
    ];

    public function handle(ImageNormalizer $normalizer): int
    {
        $normalize = (bool) $this->option('normalize');
        $deleteSource = (bool) $this->option('delete-source');
        $failed = 0;

        foreach (self::DISKS as $sourceName => [$destName, $normalizable]) {
            $inPlace = $this->sameLocation($sourceName, $destName);

            if ($inPlace && ! ($normalize && $normalizable)) {
                $this->line("<comment>{$destName}</comment>: already on local storage, skipping.");

                continue;
            }

            $source = Storage::disk($sourceName);
            $dest = Storage::disk($destName);
            $files = array_values(array_filter($source->files(), fn (string $f) => $this->isImageKey($f)));

            $this->info("{$destName}: ".count($files).' file(s)');
            $bar = $this->output->createProgressBar(count($files));

            foreach ($files as $file) {
                try {
                    $this->migrateFile($source, $dest, $file, $normalize && $normalizable, $inPlace, $deleteSource, $normalizer);
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

        if ($failed > 0) {
            $this->warn("{$failed} file(s) could not be migrated (left untouched).");
        }

        return self::SUCCESS;
    }

    private function migrateFile(
        Filesystem $source,
        Filesystem $dest,
        string $file,
        bool $normalize,
        bool $inPlace,
        bool $deleteSource,
        ImageNormalizer $normalizer,
    ): void {
        $bin = (string) $source->get($file);
        $key = $file;

        if ($normalize) {
            $hash = pathinfo($file, PATHINFO_FILENAME);
            $key = $hash.'.'.$normalizer->extension();

            // Already normalized on a previous run.
            if ($inPlace && $key === $file && pathinfo($file, PATHINFO_EXTENSION) === 'webp') {
                return;
            }

            $bin = $normalizer->normalize($bin);
        }

        if (! $dest->put($key, $bin)) {
            throw new \RuntimeException('write failed');
        }

        if (($inPlace && $key !== $file) || (! $inPlace && $deleteSource)) {
            $source->delete($file);
        }
    }

    private function isImageKey(string $file): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpe?g|png|gif|webp)$/i', $file) === 1;
    }

    private function sameLocation(string $a, string $b): bool
    {
        $ca = (array) config("filesystems.disks.{$a}");
        $cb = (array) config("filesystems.disks.{$b}");

        return ($ca['driver'] ?? null) === 'local'
            && ($cb['driver'] ?? null) === 'local'
            && realpath((string) $ca['root']) !== false
            && realpath((string) $ca['root']) === realpath((string) $cb['root']);
    }
}
