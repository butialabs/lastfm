<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'protocol' => User::PROTOCOL_AT,
            'instance' => 'https://bsky.social',
            'username' => fake()->unique()->userName().'.bsky.social',
            'did' => 'did:plc:'.fake()->sha1(),
            'password' => null,
            'token' => null,
            'lastfm_username' => fake()->userName(),
            'day_of_week' => 5,
            'time' => '12:00:00',
            'timezone' => 'UTC',
            'language' => 'en',
            'status' => User::STATUS_ACTIVE,
            'error_count' => 0,
        ];
    }

    public function mastodon(): static
    {
        return $this->state(fn () => [
            'protocol' => User::PROTOCOL_MASTODON,
            'instance' => 'https://mastodon.social',
            'username' => fake()->unique()->userName(),
            'did' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_SCHEDULE]);
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => User::STATUS_QUEUED,
            'social_montage' => '/montage/'.md5('1'),
        ]);
    }
}
