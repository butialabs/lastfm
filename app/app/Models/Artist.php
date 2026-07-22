<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /** @param Builder<Artist> $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return filled($term) ? $query->where('name', 'LIKE', '%'.$term.'%') : $query;
    }

    /**
     * "No image" filter: '1' = null/empty hash; 'placeholder' = placeholder hash.
     *
     * @param Builder<Artist> $query
     */
    public function scopeNoImage(Builder $query, ?string $mode): Builder
    {
        return match ($mode) {
            '1' => $query->where(fn (Builder $q) => $q->whereNull('image_hash')->orWhere('image_hash', '')),
            'placeholder' => $query->where('image_hash', self::PLACEHOLDER_HASH),
            default => $query,
        };
    }

    public static function updateImageHash(string $oldHash, string $newHash): int
    {
        return static::query()
            ->where('image_hash', $oldHash)
            ->update(['image_hash' => $newHash, 'updated_at' => now()]);
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

    /**
     * Aggregated appearance statistics query (admin Statistics page).
     */
    public static function appearanceStatsQuery(array $filters = []): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('artists as a')
            ->join('artist_stats as s', 'a.id', '=', 's.artist_id')
            ->selectRaw('a.id, a.name, COUNT(s.id) as appearance_count, AVG(s.position) as average_position, SUM(s.play_count) as total_plays')
            ->groupBy('a.id', 'a.name');

        if (filled($filters['from_date'] ?? null)) {
            $query->where('s.recorded_at', '>=', $filters['from_date']);
        }

        if (filled($filters['to_date'] ?? null)) {
            $query->where('s.recorded_at', '<=', $filters['to_date']);
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('a.name', 'LIKE', '%'.$filters['search'].'%');
        }

        $sortColumns = [
            'name' => 'a.name',
            'appearance_count' => 'appearance_count',
            'average_position' => 'average_position',
            'total_plays' => 'total_plays',
        ];

        $sort = $sortColumns[$filters['sort'] ?? 'appearance_count'] ?? 'appearance_count';
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order);
    }
}
