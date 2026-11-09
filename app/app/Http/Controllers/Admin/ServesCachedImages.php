<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

trait ServesCachedImages
{
    private function imageResponse(?string $bin): Response
    {
        abort_if($bin === null, 404);

        $info = @getimagesizefromstring($bin);

        return response($bin, 200, [
            'Content-Type' => $info !== false ? $info['mime'] : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
