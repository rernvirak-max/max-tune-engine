<?php

namespace Tests\Feature;

use App\Jobs\ProcessYoutubeImportJob;
use App\Models\User;
use App\Models\YoutubeImport;
use App\Services\AudioMetadataExtractor;
use App\Services\YoutubeDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class YoutubeImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    public function test_store_queues_youtube_import(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', [
            'url' => 'https://youtu.be/HKtryoXkNs4',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.video_id', 'HKtryoXkNs4');

        $this->assertDatabaseHas('youtube_imports', [
            'user_id' => $user->id,
            'video_id' => 'HKtryoXkNs4',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessYoutubeImportJob::class);
    }

    public function test_store_rejects_non_youtube_url(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', [
            'url' => 'https://example.com/song.mp3',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_index_lists_own_imports_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        YoutubeImport::query()->create([
            'user_id' => $user->id,
            'url' => 'https://youtu.be/aaaaaaaaaaa',
            'video_id' => 'aaaaaaaaaaa',
            'status' => YoutubeImport::STATUS_QUEUED,
            'status_message' => 'Queued',
        ]);
        YoutubeImport::query()->create([
            'user_id' => $other->id,
            'url' => 'https://youtu.be/bbbbbbbbbbb',
            'video_id' => 'bbbbbbbbbbb',
            'status' => YoutubeImport::STATUS_QUEUED,
            'status_message' => 'Queued',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/imports/youtube')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.video_id', 'aaaaaaaaaaa');
    }

    public function test_destroy_cancels_active_import(): void
    {
        $user = User::factory()->create();
        $import = YoutubeImport::query()->create([
            'user_id' => $user->id,
            'url' => 'https://youtu.be/HKtryoXkNs4',
            'video_id' => 'HKtryoXkNs4',
            'status' => YoutubeImport::STATUS_WAITING_METADATA,
            'status_message' => 'Waiting for metadata',
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/imports/youtube/'.$import->id)
            ->assertOk();

        $this->assertSame(YoutubeImport::STATUS_CANCELLED, $import->fresh()->status);
    }

    public function test_job_imports_track_from_downloaded_file(): void
    {
        Storage::fake('media');

        $user = User::factory()->create([
            'storage_used_bytes' => 0,
            'storage_quota_bytes' => 500 * 1024 * 1024,
        ]);

        $import = YoutubeImport::query()->create([
            'user_id' => $user->id,
            'url' => 'https://youtu.be/HKtryoXkNs4',
            'video_id' => 'HKtryoXkNs4',
            'status' => YoutubeImport::STATUS_QUEUED,
            'status_message' => 'Queued',
        ]);

        $tmp = storage_path('app/tmp/youtube-test-'.$import->id);
        if (! is_dir($tmp)) {
            mkdir($tmp, 0755, true);
        }
        $audio = $tmp.'/audio.mp3';
        file_put_contents($audio, 'fake-mp3-bytes');

        $this->mock(YoutubeDownloadService::class, function (MockInterface $mock) use ($audio): void {
            $mock->shouldReceive('fetchMetadata')->once()->andReturn([
                'id' => 'HKtryoXkNs4',
                'title' => 'Demo Song',
                'uploader' => 'Demo Artist',
                'duration' => 120,
            ]);
            $mock->shouldReceive('downloadAudio')->once()->andReturn($audio);
        });

        $this->mock(AudioMetadataExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extract')->andReturn([
                'title' => null,
                'artist_name' => null,
                'album_name' => null,
                'duration_ms' => 120000,
                'cover_binary' => null,
                'cover_mime' => null,
            ]);
        });

        (new ProcessYoutubeImportJob($import->id))->handle(
            app(YoutubeDownloadService::class),
            app(\App\Services\TrackUploadService::class),
        );

        $import->refresh();
        $this->assertSame(YoutubeImport::STATUS_DONE, $import->status);
        $this->assertNotNull($import->track_id);
        $this->assertDatabaseHas('tracks', [
            'id' => $import->track_id,
            'user_id' => $user->id,
            'title' => 'Demo Song',
            'source' => 'youtube',
            'import_mode' => 'stored',
        ]);
    }
}
