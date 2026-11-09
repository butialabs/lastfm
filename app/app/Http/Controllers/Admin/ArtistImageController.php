<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Services\LastFm\ArtistImageCache;
use App\Services\LastFm\ArtistImageFetcher;
use Illuminate\Http\Response;

class ArtistImageController extends Controller
{
    use ServesCachedImages;

    public function show(Artist $artist, ArtistImageFetcher $images, ArtistImageCache $cache): Response
    {
        return $this->imageResponse($images->cachedImage($artist) ?? $cache->placeholderBytes());
    }
}
