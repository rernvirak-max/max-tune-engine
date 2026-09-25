<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AudioMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Config::set('max-tune.mode', 'invite');

        $this->mock(AudioMetadataExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->andReturn([
                'title' => 'Test Track',
                'artist_name' => 'Tester',
                'album_name' => null,
                'duration_ms' => 1000,
                'cover_binary' => null,
                'cover_mime' => null,
            ]);
        });
    }

    public function test_oversize_upload_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Keep the fixture small: temporarily lower the max to 10 KB.
        Config::set('max-tune.max_upload_kb', 10);

        $file = UploadedFile::fake()->create('huge.mp3', 11, 'audio/mpeg');

        $this->post('/api/tracks', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_quota_full_upload_is_rejected(): void
    {
        $user = User::factory()->create([
            'storage_used_bytes' => 1000,
            'storage_quota_bytes' => 1000,
        ]);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('song.mp3', 1, 'audio/mpeg');

        $this->post('/api/tracks', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(1000, (int) $user->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('tracks', 0);
    }

    public function test_twenty_first_upload_in_an_hour_is_rate_limited(): void
    {
        $user = User::factory()->create([
            'storage_used_bytes' => 0,
            'storage_quota_bytes' => 100 * 1024 * 1024,
        ]);
        Sanctum::actingAs($user);

        $key = 'uploads:'.$user->id;
        $maxAttempts = (int) config('max-tune.upload_rate_limit', 20);
        $decay = (int) config('max-tune.upload_rate_decay_seconds', 3600);

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decay);
        }

        $file = UploadedFile::fake()->create('song.mp3', 1, 'audio/mpeg');

        $this->post('/api/tracks', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertStatus(429)
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseCount('tracks', 0);
    }
}