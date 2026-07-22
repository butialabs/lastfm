<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistStat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'artist_id',
        'user_id',
        'position',
        'play_count',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'play_count' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Artist, $this> */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
