<?php

namespace Tests\Feature;

use App\Models\Like;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LikeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_likes(): void
    {
        $this->getJson('/api/likes')->assertUnauthorized();
    }

    public function test_user_can_like_and_unlike_own_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user, 'owner')->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', true);

        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'track_id' => $track->id,
        ]);

        $this->getJson('/api/likes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $track->id)
            ->assertJsonPath('data.0.liked', true);

        $this->getJson('/api/tracks')
            ->assertOk()
            ->assertJsonPath('data.0.liked', true);

        $this->deleteJson("/api/tracks/{$track->id}/like")
            ->assertOk()
            ->assertJsonPath('data.liked', false);

        $this->assertDatabaseMissing('likes', [
            'user_id' => $user->id,
            'track_id' => $track->id,
        ]);
    }

    public function test_liking_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user, 'owner')->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/like")->assertOk();
        $this->postJson("/api/tracks/{$track->id}/like")->assertOk();

        $this->assertSame(1, Like::query()->where('track_id', $track->id)->count());
    }

    public function test_cannot_like_another_users_track(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $track = Track::factory()->for($other, 'owner')->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/tracks/{$track->id}/like")->assertNotFound();
    }
}
