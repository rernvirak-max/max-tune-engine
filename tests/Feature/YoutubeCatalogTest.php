<?php

namespace Tests\Feature;

use App\Models\MediaImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class YoutubeCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['max-tune.youtube.data_api_key' => 'test-yt-key']);
    }

    public function test_search_requires_auth_and_query(): void
    {
        $this->getJson('/api/catalog/youtube?q=love')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/catalog/youtube')->assertUnprocessable();
    }

    public function test_search_returns_mapped_youtube_videos(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'dQw4w9WgXcQ'],
                    'snippet' => [
                        'title' => 'Never Gonna Give You Up',
                        'channelTitle' => 'Rick Astley',
                        'thumbnails' => [
                            'medium' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg'],
                        ],
                    ],
                ]],
            ]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'contentDetails' => ['duration' => 'PT3M33S'],
                ]],
            ]),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Track::factory()->create([
            'user_id' => $user->id,
            'source' => MediaImport::TRACK_SOURCE,
            'source_id' => 'dQw4w9WgXcQ',
        ]);

        $this->getJson('/api/catalog/youtube?q=rick')
            ->assertOk()
            ->assertJsonPath('data.0.external_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.0.title', 'Never Gonna Give You Up')
            ->assertJsonPath('data.0.artist_name', 'Rick Astley')
            ->assertJsonPath('data.0.duration_ms', 213000)
            ->assertJsonPath('data.0.watch_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->assertJsonPath('data.0.imported', true)
            ->assertJsonPath('data.0.importing', false)
            ->assertJsonPath('data.0.source', 'youtube');
    }

    public function test_search_marks_active_imports_as_importing(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/search*' => Http::response([
                'items' => [[
                    'id' => ['videoId' => 'HKtryoXkNs4'],
                    'snippet' => [
                        'title' => 'Example',
                        'channelTitle' => 'Channel',
                        'thumbnails' => [],
                    ],
                ]],
            ]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []]),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        MediaImport::factory()->create([
            'user_id' => $user->id,
            'video_id' => 'HKtryoXkNs4',
            'status' => MediaImport::STATUS_QUEUED,
        ]);

        $this->getJson('/api/catalog/youtube?q=example')
            ->assertOk()
            ->assertJsonPath('data.0.importing', true)
            ->assertJsonPath('data.0.imported', false);
    }

    public function test_missing_api_key_returns_bad_gateway(): void
    {
        config(['max-tune.youtube.data_api_key' => '']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/catalog/youtube?q=love')
            ->assertStatus(502)
            ->assertJsonFragment([
                'message' => 'YouTube search is not configured. Set YOUTUBE_DATA_API_KEY in .env (Google Cloud → YouTube Data API v3).',
            ]);
    }
}
