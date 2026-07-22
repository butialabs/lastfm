<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Services\LastFmService;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArtistImageController extends Controller
{
    /**
     * Stream the artist's cached image (used by the Filament panel).
     */
    public function show(Artist $artist, LastFmService $lastfm): BinaryFileResponse
    {
        abort_unless(Auth::guard('admin')->check(), 403);

        $path = $lastfm->getCachedImagePath($artist);
        abort_if($path === null || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
