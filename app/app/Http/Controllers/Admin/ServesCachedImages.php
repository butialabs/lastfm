<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

trait ServesCachedImages
{
    private function imageResponse(?string $bin): Response
    {
        abort_if($bin === null, 404);

        $etag = '"'.md5($bin).'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache',
        ];

        $sent = array_map(static fn (string $t) => preg_replace('#^W/#', '', $t), request()->getETags());
        if (in_array($etag, $sent, true) || in_array('*', $sent, true)) {
            return response('', 304, $headers);
        }

        $info = @getimagesizefromstring($bin);

        return response($bin, 200, $headers + [
            'Content-Type' => $info !== false ? $info['mime'] : 'application/octet-stream',
        ]);
    }
}
