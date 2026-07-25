<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artist extends Model
{
    use HasFactory;

    public const PLACEHOLDER_HASH = '_placeholder';

    protected $fillable = [
        'name',
        'lastfm_url',
        'musicbrainz_id',
        'image_hash',
    ];

    /** @return HasMany<ArtistStat, $this> */
    public function stats(): HasMany
    {
        return $this->hasMany(ArtistStat::class);
    }

    /**
     * "No image" filter: '1' = null/empty hash; 'placeholder' = placeholder hash.
     *
     * @param  Builder<Artist>  $query
     */
    public function scopeNoImage(Builder $query, ?string $mode): Builder
    {
        return match ($mode) {
            '1' => $query->where(fn (Builder $q) => $q->whereNull('image_hash')->orWhere('image_hash', '')),
            'placeholder' => $query->where('image_hash', self::PLACEHOLDER_HASH),
            default => $query,
        };
    }

    /**
     * Artists worth another download attempt: never fetched, previously failed,
     * or holding a placeholder result older than the cutoff. Oldest attempt
     * first, so repeated failures rotate instead of starving the queue.
     *
     * @param  Builder<Artist>  $query
     */
    public function scopeNeedsImageAttempt(Builder $query, DateTimeInterface $placeholderCutoff): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q
                ->whereNull('image_hash')
                ->orWhere('image_hash', '')
                ->orWhere(fn (Builder $stale) => $stale
                    ->where('image_hash', self::PLACEHOLDER_HASH)
                    ->where('updated_at', '<', $placeholderCutoff)))
            ->orderBy('updated_at');
    }

    public function recordStats(int $userId, int $position, int $playCount): ArtistStat
    {
        return $this->stats()->create([
            'user_id' => $userId,
            'position' => $position,
            'play_count' => $playCount,
            'recorded_at' => now(),
        ]);
    }
}
