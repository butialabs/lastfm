<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Services\LastFm\ArtistImageFetcher;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArtistImageController extends Controller
{
    public function show(Artist $artist, ArtistImageFetcher $images): BinaryFileResponse
    {
        $path = $images->cachedPath($artist);
        abort_if($path === null || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
