<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Services\LastFm\AlbumImageFetcher;
use Illuminate\Http\Response;

class AlbumImageController extends Controller
{
    public function show(Album $album, AlbumImageFetcher $images): Response
    {
        $bin = $images->cachedImage($album);
        abort_if($bin === null, 404);

        $info = @getimagesizefromstring($bin);

        return response($bin, 200, [
            'Content-Type' => $info !== false ? $info['mime'] : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
