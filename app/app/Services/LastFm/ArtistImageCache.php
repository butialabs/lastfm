<?php

declare(strict_types=1);

namespace App\Services\LastFm;

use App\Services\Images\ImageCache;

// Artist images: {md5(lowercased name)}.webp on the "artist-cache" disk.
final class ArtistImageCache extends ImageCache
{
    protected function diskName(): string
    {
        return 'artist-cache';
    }
}
