<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Services\LastFm\AlbumImageCache;
use App\Services\LastFm\AlbumImageFetcher;
use Illuminate\Http\Response;

class AlbumImageController extends Controller
{
    use ServesCachedImages;

    public function show(Album $album, AlbumImageFetcher $images, AlbumImageCache $cache): Response
    {
        return $this->imageResponse($images->cachedImage($album) ?? $cache->placeholderBytes());
    }
}
