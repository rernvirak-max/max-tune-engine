<?php

namespace Tests\Feature;

use App\Exceptions\MediaImportFailedException;
use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\User;
use App\Services\YtDlp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YoutubeImportJobTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIO_BYTES = 4096;

    private const THUMBNAIL_BYTES = 512;

    private string $workRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        Queue::fake();

        $this->workRoot = storage_path('framework/testing/imports-'.uniqid());
        Config::set('max-tune.youtube.work_dir', $this->workRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workRoot);
        parent::tearDown();
    }

    public function test_success_creates_a_private_youtube_track_with_cover_and_charges_quota(): void
    {
        $user = User::factory()->create(['storage_used_bytes' => 100]);
        $import = MediaImport::factory()->for($user, 'owner')->create();
        $this->fakeYtDlp(['channel' => 'Example Artist - Topic']);

        $this->runJob($import);

        $import->refresh();
        $track = $import->track;

        $this->assertSame(MediaImport::STATUS_READY, $import->status);
        $this->assertSame(1, $import->attempts);
        $this->assertNotNull($track);
        $this->assertSame($user->id, $track->user_id);
        $this->assertSame('Example Track (Official Audio)', $track->title);
        $this->assertSame('Example Artist', $track->artist_name);
        $this->assertSame(212_000, $track->duration_ms);
        $this->assertSame('audio/mp4', $track->mime);
        $this->assertSame('youtube', $track->source);
        $this->assertSame('Ex4mpleVid0', $track->source_id);
        $this->assertSame('private', $track->visibility);
        $this->assertSame(self::AUDIO_BYTES + self::THUMBNAIL_BYTES, $track->size);
        $this->assertSame($import->thumbnail_path, $track->cover_path);
        Storage::disk('media')->assertExists([$track->storage_path, $track->cover_path]);
        $this->assertStringEndsWith('.m4a', $track->storage_path);
        $this->assertSame(100 + self::AUDIO_BYTES + self::THUMBNAIL_BYTES, (int) $user->fresh()->storage_used_bytes);
        $this->assertDirectoryDoesNotExist($import->workDir());
    }

    public function test_ready_track_behaves_like_any_track_for_its_owner(): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create();
        $this->fakeYtDlp();
        $this->runJob($import);
        $track = $import->refresh()->track;

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/tracks')->assertOk()->assertJsonPath('data.0.source', 'youtube');
        $this->get("/api/tracks/{$track->id}/stream")->assertOk();
        $this->postJson("/api/tracks/{$track->id}/like")->assertSuccessful();
        $this->deleteJson("/api/tracks/{$track->id}")->assertOk();

        $this->assertSame(0, (int) $user->fresh()->storage_used_bytes);
        Storage::disk('media')->assertMissing([$track->storage_path, $track->cover_path]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function rejectedMetadata(): array
    {
        return [
            'live now' => [['live_status' => 'is_live', 'is_live' => true], MediaImport::REASON_LIVE],
            'upcoming premiere' => [['live_status' => 'is_upcoming'], MediaImport::REASON_LIVE],
            'private' => [['availability' => 'private'], MediaImport::REASON_PRIVATE],
            'members only' => [['availability' => 'subscriber_only'], MediaImport::REASON_PRIVATE],
            'age restricted' => [['age_limit' => 18], MediaImport::REASON_AGE_RESTRICTED],
            'too long' => [['duration' => 15 * 60 + 1], MediaImport::REASON_TOO_LONG],
        ];
    }

    /**
     * @param  array<string, mixed>  $info
     */
    #[DataProvider('rejectedMetadata')]
    public function test_metadata_checks_fail_early_without_downloading_audio(array $info, string $reason): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create();
        $this->fakeYtDlp($info, expectDownload: false);

        $this->runJob($import);

        $this->assertFailedWithoutTrack($import, $reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ytDlpFailures(): array
    {
        return [
            'private' => [MediaImport::REASON_PRIVATE],
            'age restricted' => [MediaImport::REASON_AGE_RESTRICTED],
            'unavailable' => [MediaImport::REASON_UNAVAILABLE],
            'timeout' => [MediaImport::REASON_TIMEOUT],
        ];
    }

    #[DataProvider('ytDlpFailures')]
    public function test_yt_dlp_failures_are_stored_with_their_reason(string $reason): void
    {
        $import = MediaImport::factory()->create();
        $this->mock(YtDlp::class, function (MockInterface $mock) use ($reason) {
            $mock->shouldReceive('fetchMetadata')->andThrow(new MediaImportFailedException($reason, 'ERROR: detail'));
            $mock->shouldNotReceive('downloadAudio');
        });

        $this->runJob($import);

        $this->assertFailedWithoutTrack($import, $reason);
        $this->assertSame('ERROR: detail', $import->fresh()->error_detail);
    }

    public function test_blocked_by_youtube_retries_twice_with_backoff_then_fails(): void
    {
        $import = MediaImport::factory()->create();
        $this->mock(YtDlp::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchMetadata')->times(3)->andThrow(
                new MediaImportFailedException(MediaImport::REASON_BLOCKED, 'ERROR: Sign in to confirm you’re not a bot'),
            );
        });

        foreach ([60, 300] as $expectedDelay) {
            $this->runJob($import);

            $import->refresh();
            $this->assertSame(MediaImport::STATUS_QUEUED, $import->status, 'Shown as Queued during backoff');
            $this->assertNull($import->reason_code);
            Queue::assertPushed(ProcessYoutubeImport::class, fn (ProcessYoutubeImport $job) => $job->delay === $expectedDelay);
        }

        $this->runJob($import);

        $this->assertFailedWithoutTrack($import, MediaImport::REASON_BLOCKED);
        $this->assertSame(3, $import->fresh()->attempts);
        Queue::assertPushed(ProcessYoutubeImport::class, 2);
    }

    public function test_oversize_audio_fails_as_too_large_and_leaves_no_audio(): void
    {
        Config::set('max-tune.max_upload_kb', 1);
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create();
        $this->fakeYtDlp();

        $this->runJob($import);

        $this->assertFailedWithoutTrack($import, MediaImport::REASON_TOO_LARGE);
        $this->assertSame(0, (int) $user->fresh()->storage_used_bytes);
    }

    public function test_quota_is_rechecked_after_download(): void
    {
        $user = User::factory()->create(['storage_used_bytes' => 0, 'storage_quota_bytes' => self::AUDIO_BYTES]);
        $import = MediaImport::factory()->for($user, 'owner')->create();
        $this->fakeYtDlp();

        $this->runJob($import);

        $this->assertFailedWithoutTrack($import, MediaImport::REASON_QUOTA);
        $this->assertSame(0, (int) $user->fresh()->storage_used_bytes);
    }

    public function test_worker_timeout_and_lost_attempts_are_marked_failed(): void
    {
        $timedOut = MediaImport::factory()->create(['status' => MediaImport::STATUS_DOWNLOADING]);
        $lost = MediaImport::factory()->create(['video_id' => 'BBBBBBBBBBB', 'status' => MediaImport::STATUS_PROCESSING]);
        File::ensureDirectoryExists($timedOut->workDir());

        (new ProcessYoutubeImport($timedOut))->failed(new TimeoutExceededException('timed out'));
        (new ProcessYoutubeImport($lost))->failed(new MaxAttemptsExceededException('restarted'));

        $this->assertSame(MediaImport::REASON_TIMEOUT, $timedOut->fresh()->reason_code);
        $this->assertSame(MediaImport::REASON_INTERRUPTED, $lost->fresh()->reason_code);
        $this->assertDirectoryDoesNotExist($timedOut->workDir());
    }

    public function test_job_skips_imports_that_are_no_longer_queued(): void
    {
        $import = MediaImport::factory()->create(['status' => MediaImport::STATUS_FAILED]);
        $this->mock(YtDlp::class, fn (MockInterface $mock) => $mock->shouldNotReceive('fetchMetadata'));

        $this->runJob($import);

        $this->assertSame(MediaImport::STATUS_FAILED, $import->fresh()->status);
    }

    public function test_sweep_interrupts_stuck_imports_and_removes_orphaned_scratch_dirs(): void
    {
        $stuck = MediaImport::factory()->create(['status' => MediaImport::STATUS_DOWNLOADING]);
        $running = MediaImport::factory()->create(['video_id' => 'BBBBBBBBBBB', 'status' => MediaImport::STATUS_DOWNLOADING]);
        $stuck->forceFill(['updated_at' => now()->subMinutes(16)])->saveQuietly();

        File::ensureDirectoryExists($running->workDir());
        File::ensureDirectoryExists($this->workRoot.'/999');

        $this->artisan('imports:sweep')->assertSuccessful();

        $this->assertSame(MediaImport::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertSame(MediaImport::REASON_INTERRUPTED, $stuck->fresh()->reason_code);
        $this->assertSame(MediaImport::STATUS_DOWNLOADING, $running->fresh()->status);
        $this->assertDirectoryExists($running->workDir());
        $this->assertDirectoryDoesNotExist($this->workRoot.'/999');
    }

    private function runJob(MediaImport $import): void
    {
        app()->call([new ProcessYoutubeImport($import->fresh()), 'handle']);
    }

    /**
     * Stand-in for yt-dlp: writes the files the real binary would into the scratch dir.
     *
     * @param  array<string, mixed>  $info
     */
    private function fakeYtDlp(array $info = [], bool $expectDownload = true): void
    {
        $info = [
            'id' => 'Ex4mpleVid0',
            'title' => 'Example Track (Official Audio)',
            'channel' => 'Example Channel',
            'duration' => 212,
            'live_status' => 'not_live',
            'is_live' => false,
            'availability' => 'public',
            'age_limit' => 0,
            ...$info,
        ];

        $this->mock(YtDlp::class, function (MockInterface $mock) use ($info, $expectDownload) {
            $mock->shouldReceive('fetchMetadata')->once()->andReturnUsing(function (string $url, string $workDir) use ($info) {
                $this->assertSame('https://www.youtube.com/watch?v=Ex4mpleVid0', $url);
                file_put_contents("{$workDir}/media.info.json", json_encode($info));
                file_put_contents("{$workDir}/media.jpg", str_repeat('j', self::THUMBNAIL_BYTES));

                return ['info' => $info, 'info_path' => "{$workDir}/media.info.json", 'thumbnail_path' => "{$workDir}/media.jpg"];
            });

            $download = $mock->shouldReceive('downloadAudio');

            if (! $expectDownload) {
                $download->never();

                return;
            }

            $download->once()->andReturnUsing(function (string $infoPath, string $workDir) {
                file_put_contents("{$workDir}/media.m4a", str_repeat('a', self::AUDIO_BYTES));

                return "{$workDir}/media.m4a";
            });
        });
    }

    private function assertFailedWithoutTrack(MediaImport $import, string $reason): void
    {
        $import->refresh();

        $this->assertSame(MediaImport::STATUS_FAILED, $import->status);
        $this->assertSame($reason, $import->reason_code);
        $this->assertNull($import->track_id);
        $this->assertDatabaseCount('tracks', 0);
        $this->assertDirectoryDoesNotExist($import->workDir());

        // Only the failed row's own thumbnail may remain (deleted on dismiss); never audio.
        $this->assertSame(
            array_filter([$import->thumbnail_path]),
            Storage::disk('media')->allFiles(),
        );
    }
}
