<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'visibility' => 'private',
        ];
    }
}
