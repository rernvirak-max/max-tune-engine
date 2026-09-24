<?php

namespace Database\Factories;

use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Track>
 */
class TrackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'artist_name' => fake()->name(),
            'album_name' => fake()->optional()->words(2, true),
            'duration_ms' => fake()->numberBetween(60_000, 300_000),
            'mime' => 'audio/mpeg',
            'size' => fake()->numberBetween(1_000_000, 10_000_000),
            'storage_path' => "user/1/tracks/{$uuid}.mp3",
            'cover_path' => null,
            'visibility' => 'private',
            'source' => 'upload',
        ];
    }
}
