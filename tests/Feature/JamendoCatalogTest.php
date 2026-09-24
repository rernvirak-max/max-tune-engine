<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JamendoCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['max-tune.jamendo.client_id' => 'test-client']);
    }

    public function test_search_requires_auth_and_query(): void
    {
        $this->getJson('/api/catalog/jamendo?q=ambient')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/catalog/jamendo')->assertUnprocessable();
    }

    public function test_search_returns_mapped_jamendo_tracks(): void
    {
        Http::fake([
            'api.jamendo.com/*' => Http::response([
                'headers' => ['status' => 'success', 'code' => 0, 'results_count' => 1],
                'results' => [[
                    'id' => '12345',
                    'name' => 'Soft Waves',
                    'artist_name' => 'Ocean Band',
                    'album_name' => 'Shore',
                    'duration' => 180,
                    'audio' => 'https://mp3l.jamendo.com/soft.mp3',
                    'album_image' => 'https://img.jamendo.com/cover.jpg',
                    'licenses' => ['url' => 'https://creativecommons.org/licenses/by/3.0/'],
                ]],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/catalog/jamendo?q=ambient')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', '12345')
            ->assertJsonPath('data.0.title', 'Soft Waves')
            ->assertJsonPath('data.0.imported', false)
            ->assertJsonPath('data.0.stream_url', 'https://mp3l.jamendo.com/soft.mp3');
    }

    public function test_import_creates_linked_library_track(): void
    {
        Http::fake([
            'api.jamendo.com/*' => Http::response([
                'headers' => ['status' => 'success', 'code' => 0, 'results_count' => 1],
                'results' => [[
                    'id' => '99',
                    'name' => 'Night Drive',
                    'artist_name' => 'Neon',
                    'album_name' => 'City',
                    'duration' => 200,
                    'audio' => 'https://mp3l.jamendo.com/night.mp3',
                    'album_image' => 'https://img.jamendo.com/night.jpg',
                    'licenses' => ['url' => 'https://creativecommons.org/licenses/by/3.0/'],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/catalog/jamendo/import', ['external_id' => '99'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'jamendo')
            ->assertJsonPath('data.import_mode', 'linked')
            ->assertJsonPath('data.stream_url', 'https://mp3l.jamendo.com/night.mp3')
            ->assertJsonPath('data.cover_url', 'https://img.jamendo.com/night.jpg');

        $this->assertDatabaseHas('tracks', [
            'user_id' => $user->id,
            'source' => 'jamendo',
            'external_id' => '99',
            'import_mode' => 'linked',
        ]);

        // Idempotent re-import
        $this->postJson('/api/catalog/jamendo/import', ['external_id' => '99'])
            ->assertCreated();

        $this->assertSame(1, $user->tracks()->where('external_id', '99')->count());
    }

    public function test_missing_client_id_returns_bad_gateway(): void
    {
        config(['max-tune.jamendo.client_id' => '']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/catalog/jamendo?q=ambient')
            ->assertStatus(502)
            ->assertJsonFragment(['message' => 'Jamendo is not configured. Set JAMENDO_CLIENT_ID in .env (create an app at https://devportal.jamendo.com).']);
    }
}
