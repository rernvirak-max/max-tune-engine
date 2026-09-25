<?php

namespace Tests\Feature;

use App\Models\Like;
use App\Models\MediaImport;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IsolationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_list_another_users_tracks(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Track::factory()->for($a, 'owner')->create(['title' => 'Secret A']);

        Sanctum::actingAs($b);

        $this->getJson('/api/tracks')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_cannot_show_update_or_delete_foreign_track(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $track = Track::factory()->for($a, 'owner')->create();

        Sanctum::actingAs($b);

        $this->getJson("/api/tracks/{$track->id}")->assertForbidden();
        $this->deleteJson("/api/tracks/{$track->id}")->assertForbidden();
    }

    public function test_user_cannot_stream_or_cover_foreign_track(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $track = Track::factory()->for($a, 'owner')->create([
            'cover_path' => 'covers/x.jpg',
            'storage_path' => 'audio/x.mp3',
        ]);

        Sanctum::actingAs($b);

        $this->getJson("/api/tracks/{$track->id}/stream")->assertForbidden();
        $this->getJson("/api/tracks/{$track->id}/cover")->assertForbidden();
    }

    public function test_user_cannot_see_foreign_playlists_or_likes(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $track = Track::factory()->for($a, 'owner')->create();
        $playlist = Playlist::factory()->for($a, 'owner')->create(['title' => 'Private list']);
        Like::query()->create(['user_id' => $a->id, 'track_id' => $track->id]);

        Sanctum::actingAs($b);

        $this->getJson('/api/playlists')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/playlists/{$playlist->id}")->assertNotFound();

        $this->getJson('/api/likes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_imported_youtube_track_is_as_private_as_an_upload(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $track = Track::factory()->for($a, 'owner')->create([
            'source' => 'youtube',
            'source_id' => 'Ex4mpleVid0',
            'cover_path' => 'covers/x.jpg',
        ]);

        Sanctum::actingAs($b);

        $this->getJson('/api/tracks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/tracks/{$track->id}")->assertForbidden();
        $this->deleteJson("/api/tracks/{$track->id}")->assertForbidden();
        $this->getJson("/api/tracks/{$track->id}/stream")->assertForbidden();
        $this->getJson("/api/tracks/{$track->id}/cover")->assertForbidden();
        $this->postJson("/api/tracks/{$track->id}/like")->assertNotFound();
    }

    public function test_user_cannot_see_or_touch_foreign_imports(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $import = MediaImport::factory()->for($a, 'owner')->create([
            'status' => MediaImport::STATUS_FAILED,
            'thumbnail_path' => 'covers/thumb.jpg',
        ]);

        Sanctum::actingAs($b);

        $this->getJson('/api/imports')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/imports/{$import->id}")->assertNotFound();
        $this->postJson("/api/imports/{$import->id}/retry")->assertNotFound();
        $this->deleteJson("/api/imports/{$import->id}")->assertNotFound();
        $this->getJson("/api/imports/{$import->id}/thumbnail")->assertForbidden();
        $this->assertModelExists($import);
    }
}
