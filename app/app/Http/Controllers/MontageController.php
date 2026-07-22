<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MontageController extends Controller
{
    /**
     * Serve data/montage/{hash}.jpg — public route preserved from the legacy
     * app (filename = md5(user_id)).
     */
    public function show(string $hash): BinaryFileResponse
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/i', $hash) === 1, 404);

        $path = Storage::disk('montage')->path(strtolower($hash).'.jpg');
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)).' GMT',
            'ETag' => md5_file($path),
        ]);
    }
}
