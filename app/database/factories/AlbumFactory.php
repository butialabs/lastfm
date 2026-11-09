<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Album;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Album> */
class AlbumFactory extends Factory
{
    protected $model = Album::class;

    public function definition(): array
    {
        $artist = fake()->unique()->words(2, true);
        $name = fake()->words(3, true);

        return [
            'artist_name' => $artist,
            'name' => $name,
            'musicbrainz_id' => null,
            'image_hash' => md5(strtolower($artist.'|'.$name)),
        ];
    }
}
