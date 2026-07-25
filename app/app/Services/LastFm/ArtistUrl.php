<?php

declare(strict_types=1);

namespace App\Services\LastFm;

final class ArtistUrl
{
    // NFC-normalizes Unicode and converts %20 → '+' (Last.fm URL convention).
    public static function for(string $artistName): string
    {
        $name = trim($artistName);

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $name = $normalized;
            }
        }

        return 'https://www.last.fm/music/'.str_replace('%20', '+', rawurlencode($name));
    }
}
