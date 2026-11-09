<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Services\LastFm\ArtistImageFetcher;
use Illuminate\Http\Response;

class ArtistImageController extends Controller
{
    public function show(Artist $artist, ArtistImageFetcher $images): Response
    {
        $bin = $images->cachedImage($artist);
        abort_if($bin === null, 404);

        $info = @getimagesizefromstring($bin);

        return response($bin, 200, [
            'Content-Type' => $info !== false ? $info['mime'] : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
