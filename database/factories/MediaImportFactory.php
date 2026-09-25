<?php

namespace Database\Factories;

use App\Models\MediaImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaImport>
 */
class MediaImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => fn (array $attributes) => 'https://www.youtube.com/watch?v='.$attributes['video_id'],
            'video_id' => 'Ex4mpleVid0',
            'status' => MediaImport::STATUS_QUEUED,
        ];
    }
}
