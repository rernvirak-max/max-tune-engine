<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_playlists(): void
    {
        $this->getJson('/api/playlists')->assertUnauthorized();
    }

    public function test_user_can_create_and_list_playlists(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/playlists', [
            'title' => 'Gym',
            'description' => 'Push days',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Gym')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.track_count', 0);

        $this->getJson('/api/playlists')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Gym');
    }

    public function test_user_can_attach_own_track_and_view_playlist(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user, 'owner')->create(['title' => 'Focus']);
        $track = Track::factory()->for($user, 'owner')->create(['title' => 'Ambient One']);

        Sanctum::actingAs($user);

        $this->postJson("/api/playlists/{$playlist->id}/tracks", [
            'track_id' => $track->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.track_count', 1)
            ->assertJsonPath('data.tracks.0.id', $track->id);

        $this->getJson("/api/playlists/{$playlist->id}")
            ->assertOk()
            ->assertJsonPath('data.tracks.0.title', 'Ambient One');
    }

    public function test_cannot_attach_another_users_track(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $playlist = Playlist::factory()->for($user, 'owner')->create();
        $foreignTrack = Track::factory()->for($other, 'owner')->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/playlists/{$playlist->id}/tracks", [
            'track_id' => $foreignTrack->id,
        ])->assertNotFound();
    }

    public function test_foreign_playlist_is_hidden(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $playlist = Playlist::factory()->for($other, 'owner')->create();

        Sanctum::actingAs($user);

        $this->getJson("/api/playlists/{$playlist->id}")->assertNotFound();
    }

    public function test_user_can_detach_track_and_delete_playlist(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user, 'owner')->create();
        $track = Track::factory()->for($user, 'owner')->create();
        $playlist->tracks()->attach($track->id, ['position' => 0]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/playlists/{$playlist->id}/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.track_count', 0);

        $this->deleteJson("/api/playlists/{$playlist->id}")
            ->assertOk();

        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
    }
}
