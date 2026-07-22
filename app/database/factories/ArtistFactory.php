<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Artist> */
class ArtistFactory extends Factory
{
    protected $model = Artist::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'lastfm_url' => 'https://www.last.fm/music/'.str_replace(' ', '+', $name),
            'musicbrainz_id' => null,
            'image_hash' => md5(strtolower($name)),
        ];
    }
}
