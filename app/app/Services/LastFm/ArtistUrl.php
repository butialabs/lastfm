<?php

declare(strict_types=1);

namespace App\Services\LastFm;

final class ArtistUrl
{
    // NFC-normalizes Unicode and converts %20 → '+' (Last.fm URL convention).
    public static function for(string $artistName): string
    {
        return 'https://www.last.fm/music/'.self::segment($artistName);
    }

    public static function forAlbum(string $artistName, string $albumName): string
    {
        return self::for($artistName).'/'.self::segment($albumName);
    }

    private static function segment(string $value): string
    {
        $name = trim($value);

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $name = $normalized;
            }
        }

        return str_replace('%20', '+', rawurlencode($name));
    }
}
