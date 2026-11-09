<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Album extends Model
{
    use HasFactory;

    public const PLACEHOLDER_HASH = '_placeholder';

    protected $fillable = [
        'artist_name',
        'name',
        'musicbrainz_id',
        'image_hash',
    ];

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_name', 'name');
    }

    /**
     * @param  Builder<Album>  $query
     */
    public function scopeWithImage(Builder $query): Builder
    {
        return $query->whereNotNull('image_hash')->where('image_hash', '!=', '');
    }
}
