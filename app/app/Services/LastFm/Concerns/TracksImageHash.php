<?php

declare(strict_types=1);

namespace App\Services\LastFm\Concerns;

use App\Services\LastFm\ImageOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * image_hash bookkeeping shared by the artist and album image fetchers.
 *
 * image_hash holds either the cache hash, the model's PLACEHOLDER_HASH sentinel
 * (Last.fm has no image) or null/'' when nothing has been fetched yet. A
 * placeholder is attempted again once it is older than placeholder_retry_days.
 *
 * A failed attempt (blocked, timeout, challenge page...) never writes the
 * placeholder: the record keeps its current state and the automatic flow only
 * waits failure_retry_hours before trying again.
 */
trait TracksImageHash
{
    protected function placeholderExpired(Model $model): bool
    {
        return $model->updated_at !== null && $model->updated_at->lt($this->placeholderCutoff());
    }

    protected function placeholderCutoff(): Carbon
    {
        return now()->subDays((int) config('lastfm.images.placeholder_retry_days', 30));
    }

    /**
     * Persists the outcome of a download for $model.
     *
     *  - Stored: points image_hash at the cached file.
     *  - Placeholder: records the sentinel, unless the model already has a real
     *    image (an existing image is never downgraded automatically).
     *  - Failed: leaves the model untouched.
     */
    protected function applyOutcome(Model $model, ImageOutcome $outcome, string $hash, string $placeholderHash): void
    {
        match ($outcome) {
            ImageOutcome::Stored => $this->rememberHash($model, $hash),
            ImageOutcome::Placeholder => $this->hasRealImage($model, $placeholderHash) && $this->cache->has((string) $model->image_hash)
                ? null
                : $this->rememberHash($model, $placeholderHash),
            ImageOutcome::Failed => null,
        };
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

    protected function hasRealImage(Model $model, string $placeholderHash): bool
    {
        return ! empty($model->image_hash) && $model->image_hash !== $placeholderHash;
    }

    protected function recentlyFailed(string $hash): bool
    {
        return Cache::has($this->failureKey($hash));
    }

    protected function rememberFailure(string $hash): void
    {
        $hours = (int) config('lastfm.images.failure_retry_hours', 6);

        if ($hours > 0) {
            Cache::put($this->failureKey($hash), true, now()->addHours($hours));
        }
    }

    protected function forgetFailure(string $hash): void
    {
        Cache::forget($this->failureKey($hash));
    }

    private function failureKey(string $hash): string
    {
        return 'image-failed:'.static::class.':'.$hash;
    }
}
