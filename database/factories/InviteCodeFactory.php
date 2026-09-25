<?php

namespace Database\Factories;

use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InviteCode>
 */
class InviteCodeFactory extends Factory
{
    protected $model = InviteCode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(10)),
            'label' => fake()->optional()->words(2, true),
            'max_uses' => 1,
            'uses_count' => 0,
            'expires_at' => null,
            'is_active' => true,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes) => [
            'max_uses' => 1,
            'uses_count' => 1,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
