<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Album extends Model
{
    public const PLACEHOLDER_HASH = '_placeholder';

    protected $fillable = [
        'artist_name',
        'name',
        'musicbrainz_id',
        'image_hash',
    ];
}
