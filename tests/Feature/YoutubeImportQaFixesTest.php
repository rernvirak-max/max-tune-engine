<?php

namespace Tests\Feature;

use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA round 1 (B1, B4, B5, B6): 403 during the audio download, empty urls,
 * deleted imported tracks and failed-import retention.
 */
class YoutubeImportQaFixesTest extends TestCase
{
    use RefreshDatabase;

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        Storage::fake('local');

        $this->workRoot = storage_path('framework/testing/imports-qa-'.uniqid());
        Config::set('max-tune.youtube.work_dir', $this->workRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workRoot);
        parent::tearDown();
    }

    public function test_youtube_403_during_the_audio_download_is_retried_as_blocked(): void
    {
        // Real YtDlp wrapper against a stub binary: metadata works, the audio download gets 403.
        $stub = $this->workRoot.'/yt-dlp-stub';
        File::ensureDirectoryExists($this->workRoot);
        file_put_contents($stub, <<<'SH'
#!/bin/sh
case " $* " in
  *" --skip-download "*)
    printf '{"id":"Ex4mpleVid0","title":"T","duration":60,"live_status":"not_live","availability":"public","age_limit":0}' > "$PWD/media.info.json"
    ;;
  *)
    echo 'ERROR: unable to download video data: HTTP Error 403: Forbidden' >&2
    exit 1
    ;;
esac
SH);
        chmod($stub, 0755);
        Config::set('max-tune.youtube.ytdlp_binary', $stub);
        $import = MediaImport::factory()->create();

        app()->call([new ProcessYoutubeImport($import->fresh()), 'handle']);

        $import->refresh();
        $this->assertSame(MediaImport::STATUS_QUEUED, $import->status, 'Shown as Queued while waiting for the automatic retry');
        $this->assertNull($import->reason_code);
        $this->assertStringContainsString('HTTP Error 403: Forbidden', (string) $import->error_detail);
        Queue::assertPushed(ProcessYoutubeImport::class, fn (ProcessYoutubeImport $job) => $job->delay === 60);
        $this->assertDatabaseCount('tracks', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingUrls(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['url' => '']],
            'blank' => [['url' => '   ']],
            'null' => [['url' => null]],
            'not a string' => [['url' => ['https://youtu.be/Ex4mpleVid0']]],
            'too long' => [['url' => 'https://youtu.be/Ex4mpleVid0?x='.str_repeat('a', 2048)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingUrls')]
    public function test_missing_or_empty_url_is_invalid_url_in_the_same_shape(array $payload): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/imports/youtube', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_url')
            ->assertJsonMissingPath('errors');

        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_imports', 0);
    }

    public function test_deleting_an_imported_track_unlinks_its_import_so_admin_shows_the_placeholder(): void
    {
        $friend = User::factory()->create(['storage_used_bytes' => 1500]);
        Storage::disk('media')->put('user/1/tracks/a.m4a', 'audio');
        Storage::disk('media')->put('user/1/covers/c.jpg', 'jpg');
        $track = Track::factory()->for($friend, 'owner')->create([
            'size' => 1500,
            'storage_path' => 'user/1/tracks/a.m4a',
            'cover_path' => 'user/1/covers/c.jpg',
            'source' => 'youtube',
            'source_id' => 'Ex4mpleVid0',
        ]);
        $import = MediaImport::factory()->for($friend, 'owner')->create([
            'status' => MediaImport::STATUS_READY,
            'track_id' => $track->id,
            'thumbnail_path' => 'user/1/covers/c.jpg',
        ]);

        Sanctum::actingAs($friend);
        $this->deleteJson("/api/tracks/{$track->id}")->assertOk();

        $import->refresh();
        $this->assertNull($import->track_id);
        $this->assertNull($import->thumbnail_path);
        Storage::disk('media')->assertMissing('user/1/covers/c.jpg');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/admin/imports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $import->id)
            ->assertJsonPath('data.0.track_id', null)
            ->assertJsonPath('data.0.thumbnail_url', null);

        // The import can still be removed by the admin without touching anything else.
        $this->deleteJson("/api/admin/imports/{$import->id}")->assertOk();
        $this->assertModelMissing($import);
    }

    public function test_sweep_deletes_failed_imports_and_thumbnails_after_the_retention_period(): void
    {
        Config::set('max-tune.youtube.failed_retention_days', 7);
        Storage::disk('media')->put('user/1/covers/old.jpg', 'jpg');
        Storage::disk('media')->put('user/1/covers/recent.jpg', 'jpg');
        $old = MediaImport::factory()->create([
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => MediaImport::REASON_UNAVAILABLE,
            'thumbnail_path' => 'user/1/covers/old.jpg',
        ]);
        $recent = MediaImport::factory()->create([
            'video_id' => 'BBBBBBBBBBB',
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => MediaImport::REASON_UNAVAILABLE,
            'thumbnail_path' => 'user/1/covers/recent.jpg',
        ]);
        $oldReady = MediaImport::factory()->create(['video_id' => 'CCCCCCCCCCC', 'status' => MediaImport::STATUS_READY]);
        $old->forceFill(['updated_at' => now()->subDays(8)])->saveQuietly();
        $recent->forceFill(['updated_at' => now()->subDays(6)])->saveQuietly();
        $oldReady->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

        $this->artisan('imports:sweep')->assertSuccessful();

        $this->assertModelMissing($old);
        Storage::disk('media')->assertMissing('user/1/covers/old.jpg');
        $this->assertModelExists($recent);
        Storage::disk('media')->assertExists('user/1/covers/recent.jpg');
        $this->assertModelExists($oldReady);
    }
}
