<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Artist;
use App\Services\LastFm\AlbumImageFetcher;
use App\Services\LastFm\ArtistImageFetcher;
use App\Services\LastFm\ImageOutcome;
use App\Services\LastFm\ProxiedHttp;
use App\Services\LastFm\RequestPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class RecheckImagesCommand extends Command
{
    protected $signature = 'lastfm:images:recheck
        {type=all : all | artists | albums}
        {--missing : Also include records that never got an image (empty image_hash)}
        {--id=* : Only these ids (of the selected type)}
        {--limit=0 : Maximum records per type (0 = no limit)}
        {--attempts=5 : Attempts per request, all through the proxy}
        {--min-delay=5 : Minimum pause before each request (seconds)}
        {--max-delay=60 : Maximum pause before each request (seconds)}
        {--dry-run : Only list what would be processed}';

    protected $description = 'Re-download images of artists/albums on the placeholder, proxy only, with retries and random pauses';

    /** @var array<string, array<string,int>> */
    private array $totals = [];

    public function handle(ProxiedHttp $http): int
    {
        $type = (string) $this->argument('type');
        if (! in_array($type, ['all', 'artists', 'albums'], true)) {
            $this->error('type must be one of: all, artists, albums');

            return self::INVALID;
        }

        $minDelay = max(0, (int) $this->option('min-delay'));
        $maxDelay = max($minDelay, (int) $this->option('max-delay'));
        $attempts = max(1, (int) $this->option('attempts'));

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && $http->proxyUrls() === []) {
            $this->error('LASTFM_PROXY_URL is not configured: this command only runs through the proxy.');

            return self::FAILURE;
        }

        $this->laravel->instance(ProxiedHttp::class, $http->withPolicy(RequestPolicy::strict($attempts, $minDelay, $maxDelay)));

        if (! $dryRun) {
            $this->info(sprintf(
                'Proxy only · %d attempt(s) per request · pause %d–%ds before each request · %d proxy(ies)',
                $attempts, $minDelay, $maxDelay, count($http->proxyUrls()),
            ));
        }

        if ($type !== 'albums') {
            $this->processArtists($dryRun);
        }

        if ($type !== 'artists') {
            $this->processAlbums($dryRun);
        }

        if (! $dryRun) {
            $this->summary();
        }

        return self::SUCCESS;
    }

    private function processArtists(bool $dryRun): void
    {
        $query = $this->scope(Artist::query(), Artist::PLACEHOLDER_HASH);
        $total = $this->countFor($query);

        $this->newLine();
        $this->info("Artists: {$total}");

        if ($total === 0) {
            return;
        }

        $fetcher = $this->laravel->make(ArtistImageFetcher::class);

        $this->each($query, $total, function (Artist $artist, string $prefix) use ($dryRun, $fetcher): void {
            if ($dryRun) {
                $this->line("{$prefix} #{$artist->id} {$artist->name}");

                return;
            }

            $this->report('artists', $prefix, "#{$artist->id} {$artist->name}", $fetcher->regenerate((int) $artist->id));
        });
    }

    private function processAlbums(bool $dryRun): void
    {
        $query = $this->scope(Album::query(), Album::PLACEHOLDER_HASH);
        $total = $this->countFor($query);

        $this->newLine();
        $this->info("Albums: {$total}");

        if ($total === 0) {
            return;
        }

        $fetcher = $this->laravel->make(AlbumImageFetcher::class);

        $this->each($query, $total, function (Album $album, string $prefix) use ($dryRun, $fetcher): void {
            $label = "#{$album->id} {$album->artist_name} — {$album->name}";

            if ($dryRun) {
                $this->line("{$prefix} {$label}");

                return;
            }

            $this->report('albums', $prefix, $label, $fetcher->regenerate($album));
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scope(Builder $query, string $placeholderHash): Builder
    {
        $ids = array_filter(array_map('intval', (array) $this->option('id')));

        if ($ids !== []) {
            return $query->whereKey($ids)->orderBy('updated_at')->orderBy('id');
        }

        $missing = (bool) $this->option('missing');

        return $query
            ->where(function (Builder $q) use ($placeholderHash, $missing): void {
                $q->where('image_hash', $placeholderHash);

                if ($missing) {
                    $q->orWhereNull('image_hash')->orWhere('image_hash', '');
                }
            })
            ->orderBy('updated_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function countFor(Builder $query): int
    {
        $count = (clone $query)->count();
        $limit = max(0, (int) $this->option('limit'));

        return $limit > 0 ? min($count, $limit) : $count;
    }

    /**
     * Ids are read up front: processing touches updated_at, which would
     * otherwise reshuffle an ordered cursor.
     *
     * @param  Builder<Model>  $query
     */
    private function each(Builder $query, int $total, callable $callback): void
    {
        $ids = (clone $query)->limit($total)->pluck('id');
        $model = $query->getModel();
        $width = strlen((string) $total);

        foreach ($ids as $i => $id) {
            $record = $model->newQuery()->find($id);
            if ($record === null) {
                continue;
            }

            $callback($record, sprintf('[%'.$width.'d/%d]', $i + 1, $total));
        }
    }

    private function report(string $type, string $prefix, string $label, ImageOutcome $outcome): void
    {
        $this->totals[$type][$outcome->value] = ($this->totals[$type][$outcome->value] ?? 0) + 1;

        $tag = match ($outcome) {
            ImageOutcome::Stored => '<info>recovered</info>',
            ImageOutcome::Placeholder => '<comment>no image on Last.fm</comment>',
            ImageOutcome::Failed => '<error>failed</error>',
        };

        $this->line("{$prefix} {$label} → {$tag}");

        Log::channel('artist_images')->info('Image recheck', [
            'type' => $type,
            'item' => $label,
            'outcome' => $outcome->value,
        ]);
    }

    private function summary(): void
    {
        if ($this->totals === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['Type', 'Recovered', 'No image on Last.fm', 'Failed (unchanged)'],
            collect($this->totals)->map(fn (array $t, string $type) => [
                $type,
                $t[ImageOutcome::Stored->value] ?? 0,
                $t[ImageOutcome::Placeholder->value] ?? 0,
                $t[ImageOutcome::Failed->value] ?? 0,
            ])->values()->all(),
        );
    }
}
