<?php

declare(strict_types=1);

namespace App\Services\LastFm\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * image_hash bookkeeping shared by the artist and album image fetchers.
 *
 * image_hash holds either the cache hash, the model's PLACEHOLDER_HASH sentinel
 * (the download failed or a stub image was served) or null/'' when nothing has
 * been fetched yet. A placeholder is attempted again once it is older than
 * placeholder_retry_days.
 */
trait TracksImageHash
{
    protected const STORED = 'stored';

    protected const PLACEHOLDER = 'placeholder';

    protected const FAILED = 'failed';

    protected function placeholderExpired(Model $model): bool
    {
        return $model->updated_at !== null && $model->updated_at->lt($this->placeholderCutoff());
    }

    protected function placeholderCutoff(): Carbon
    {
        return now()->subDays((int) config('lastfm.images.placeholder_retry_days', 30));
    }

    protected function rememberHash(Model $model, string $hash): void
    {
        if ($model->image_hash !== $hash) {
            $model->update(['image_hash' => $hash]);

            return;
        }

        // Same outcome as last time — refresh the timestamp so a placeholder
        // does not stay permanently expired.
        $model->touch();
    }
}
