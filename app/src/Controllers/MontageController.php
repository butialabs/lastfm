<?php

declare(strict_types=1);

namespace App\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MontageController
{
    public function __construct(
        private readonly string $dataPath,
    ) {
    }

    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $hash = $args['hash'] ?? '';

        if (!preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        $filePath = $this->dataPath . '/montage/' . $hash . '.jpg';

        if (!is_file($filePath)) {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not Found');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error');
        }

        $lastModified = filemtime($filePath);
        $etag = md5_file($filePath);

        return new Response(200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'public, max-age=86400',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified ?: time()) . ' GMT',
            'ETag' => '"' . $etag . '"',
        ], $content);
    }
}
