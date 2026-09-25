<?php

namespace Tests\Feature;

use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YoutubeImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=Ex4mpleVid0', 'Ex4mpleVid0'],
            'watch with list= ignored' => ['https://www.youtube.com/watch?v=Ex4mpleVid0&list=PLexample123', 'Ex4mpleVid0'],
            'mobile' => ['https://m.youtube.com/watch?v=Ex4mpleVid0&t=42', 'Ex4mpleVid0'],
            'youtu.be' => ['https://youtu.be/Ex4mpleVid0?si=abc', 'Ex4mpleVid0'],
            'music' => ['https://music.youtube.com/watch?v=Ex4mpleVid0', 'Ex4mpleVid0'],
            'shorts' => ['https://youtube.com/shorts/Ex4mpleVid0', 'Ex4mpleVid0'],
            'no scheme' => ['  youtube.com/watch?v=Ex4mpleVid0  ', 'Ex4mpleVid0'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_valid_single_video_url_is_queued(string $url, string $videoId): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', ['url' => $url])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.video_id', $videoId)
            // Rebuilt from the ID; the raw input is never stored or passed on.
            ->assertJsonPath('data.url', "https://www.youtube.com/watch?v={$videoId}");

        Queue::assertPushedOn('imports', ProcessYoutubeImport::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'not youtube' => ['https://vimeo.com/123456'],
            'lookalike host' => ['https://youtube.com.evil.test/watch?v=Ex4mpleVid0'],
            'channel' => ['https://www.youtube.com/@someone'],
            'bad id' => ['https://www.youtube.com/watch?v=short'],
            'ambiguous v=' => ['https://www.youtube.com/watch?v=Ex4mpleVid0&v=0therVide01'],
            'javascript scheme' => ['javascript:alert(1)//youtube.com/watch?v=Ex4mpleVid0'],
            'garbage' => ['not a url at all'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_url_is_rejected_and_nothing_is_queued(string $url): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/imports/youtube', ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_url');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_imports', 0);
    }

    public function test_playlist_url_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/imports/youtube', ['url' => 'https://www.youtube.com/playlist?list=PLexample123'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'playlist_not_supported');

        Queue::assertNothingPushed();
    }

    public function test_duplicate_ready_track_returns_409_with_existing_track(): void
    {
        $user = User::factory()->create();
        $track = Track::factory()->for($user, 'owner')->create(['source' => 'youtube', 'source_id' => 'Ex4mpleVid0']);
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])
            ->assertConflict()
            ->assertJsonPath('code', 'duplicate')
            ->assertJsonPath('existing.type', 'track')
            ->assertJsonPath('existing.id', $track->id);

        Queue::assertNothingPushed();
    }

    public function test_duplicate_in_flight_import_returns_409_with_existing_import(): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create(['status' => MediaImport::STATUS_DOWNLOADING]);
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])
            ->assertConflict()
            ->assertJsonPath('existing.type', 'import')
            ->assertJsonPath('existing.id', $import->id);
    }

    public function test_same_video_is_allowed_for_a_different_user(): void
    {
        $other = User::factory()->create();
        Track::factory()->for($other, 'owner')->create(['source' => 'youtube', 'source_id' => 'Ex4mpleVid0']);
        MediaImport::factory()->for($other, 'owner')->create();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])
            ->assertAccepted();
    }

    public function test_user_over_quota_cannot_submit(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'storage_used_bytes' => 1000,
            'storage_quota_bytes' => 1000,
        ]));

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'quota_exceeded');

        Queue::assertNothingPushed();
    }

    public function test_third_concurrent_import_is_rejected(): void
    {
        $user = User::factory()->create();
        MediaImport::factory()->for($user, 'owner')->create(['video_id' => 'AAAAAAAAAAA']);
        MediaImport::factory()->for($user, 'owner')->create(['video_id' => 'BBBBBBBBBBB', 'status' => MediaImport::STATUS_DOWNLOADING]);
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_imports');
    }

    public function test_submissions_are_rate_limited_per_user_with_retry_after(): void
    {
        Config::set('max-tune.youtube.rate_limit', 1);
        $user = User::factory()->create();
        RateLimiter::clear('youtube-imports:'.$user->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/AAAAAAAAAAA'])->assertAccepted();

        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/BBBBBBBBBBB'])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'code', 'retry_after']);
    }

    public function test_list_filters_by_status_and_hides_admin_detail(): void
    {
        $user = User::factory()->create();
        MediaImport::factory()->for($user, 'owner')->create(['video_id' => 'AAAAAAAAAAA']);
        MediaImport::factory()->for($user, 'owner')->create([
            'video_id' => 'BBBBBBBBBBB',
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => MediaImport::REASON_BLOCKED,
            'error_detail' => 'ERROR: Sign in to confirm you are not a bot',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/imports?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.video_id', 'AAAAAAAAAAA');

        $this->getJson('/api/imports?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason_code', 'blocked_by_youtube')
            ->assertJsonMissingPath('data.0.error_detail')
            ->assertJsonMissingPath('data.0.attempts');

        $this->getJson('/api/imports')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_retry_requeues_a_failed_import(): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create([
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => MediaImport::REASON_TIMEOUT,
            'attempts' => 1,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/imports/{$import->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.reason_code', null);

        $this->assertSame(0, $import->fresh()->attempts);
        Queue::assertPushed(ProcessYoutubeImport::class);
    }

    public function test_only_failed_imports_can_be_retried(): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/imports/{$import->id}/retry")->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_dismiss_deletes_queued_or_failed_import_and_its_thumbnail(): void
    {
        $user = User::factory()->create();
        Storage::disk('media')->put('user/1/covers/thumb.jpg', 'jpg');
        $import = MediaImport::factory()->for($user, 'owner')->create([
            'status' => MediaImport::STATUS_FAILED,
            'thumbnail_path' => 'user/1/covers/thumb.jpg',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/imports/{$import->id}")->assertOk();

        $this->assertModelMissing($import);
        Storage::disk('media')->assertMissing('user/1/covers/thumb.jpg');
    }

    public function test_downloading_import_cannot_be_dismissed(): void
    {
        $user = User::factory()->create();
        $import = MediaImport::factory()->for($user, 'owner')->create(['status' => MediaImport::STATUS_DOWNLOADING]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/imports/{$import->id}")->assertUnprocessable();
        $this->assertModelExists($import);
    }

    public function test_thumbnail_is_served_to_owner_or_signed_url_only(): void
    {
        $owner = User::factory()->create();
        Storage::disk('media')->put('user/1/covers/thumb.jpg', 'jpg');
        $import = MediaImport::factory()->for($owner, 'owner')->create(['thumbnail_path' => 'user/1/covers/thumb.jpg']);

        Sanctum::actingAs($owner);
        $signedUrl = $this->getJson("/api/imports/{$import->id}")->assertOk()->json('data.thumbnail_url');
        $this->assertStringContainsString('signature=', $signedUrl);

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/imports/{$import->id}/thumbnail")->assertForbidden();
        $this->get(parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY))->assertOk();
    }

    public function test_disabled_user_cannot_submit_and_queued_imports_are_cancelled(): void
    {
        $admin = User::factory()->admin()->create();
        $friend = User::factory()->create();
        $queued = MediaImport::factory()->for($friend, 'owner')->create();
        $failed = MediaImport::factory()->for($friend, 'owner')->create(['video_id' => 'BBBBBBBBBBB', 'status' => MediaImport::STATUS_FAILED]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$friend->id}/disable")->assertOk();

        $this->assertModelMissing($queued);
        $this->assertModelExists($failed);

        Sanctum::actingAs($friend->fresh());
        $this->postJson('/api/imports/youtube', ['url' => 'https://youtu.be/Ex4mpleVid0'])->assertForbidden();
    }
}
