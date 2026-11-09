<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Services\Images\ImageCache;

// Album covers: {md5(lower(artist|album))}.webp on the "album-cache" disk.
final class AlbumImageCache extends ImageCache
{
    protected function diskName(): string
    {
        return 'album-cache';
    }
}
