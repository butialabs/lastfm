<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;

class MontageController extends Controller
{
    // Serves {hash}.jpg from the "montage" disk (local or S3) — public route
    // preserved from the legacy app (filename = md5(user_id)).
    public function show(string $hash): Response
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/i', $hash) === 1, 404);

        $bin = User::montageContents('/montage/'.$hash);
        abort_if($bin === null, 404);

        $etag = sprintf('"%s"', md5($bin));

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ];

        if (request()->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        return response($bin, 200, $headers + ['Content-Length' => (string) strlen($bin)]);
    }
}
