<?php

declare(strict_types=1);

namespace App\Services\LastFm;

final class LastFmPlaceholders
{
    public static function matches(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach ((array) config('lastfm.images.lastfm_placeholder_ids', []) as $id) {
            $id = strtolower(trim((string) $id));
            if ($id !== '' && str_contains($path, $id)) {
                return true;
            }
        }

        return false;
    }
}
