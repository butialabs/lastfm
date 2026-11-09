<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class MontageController extends Controller
{
    // Serves {hash}.jpg from the "montage" disk (local or S3) — public route
    // preserved from the legacy app (filename = md5(user_id)).
    public function show(string $hash): Response
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/i', $hash) === 1, 404);

        $disk = Storage::disk('montage');
        $key = strtolower($hash).'.jpg';
        abort_unless($disk->exists($key), 404);

        $bin = $disk->get($key);
        abort_unless(is_string($bin) && $bin !== '', 404);

        $mtime = (int) $disk->lastModified($key);
        $etag = sprintf('"%s"', md5($hash.$mtime));

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT',
            'ETag' => $etag,
        ];

        if (request()->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($bin, 200, $headers + ['Content-Length' => (string) strlen($bin)]);
    }
}
