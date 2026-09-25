<?php

namespace Tests\Feature;

use App\Models\MediaImport;
use App\Models\Track;
use App\Models\User;
use App\Services\YtDlp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminYoutubeImportTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIES = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\ttop-secret-value\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('media');
    }

    public function test_admin_uploads_cookies_stored_encrypted_and_never_returned(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->mock(YtDlp::class, fn (MockInterface $mock) => $mock->shouldReceive('version')->andReturn('2026.08.19'));

        $this->getJson('/api/admin/youtube/status')
            ->assertOk()
            ->assertExactJson(['data' => ['yt_dlp_version' => '2026.08.19', 'cookies_set' => false]]);

        $response = $this->putJson('/api/admin/youtube/cookies', ['cookies' => self::COOKIES])
            ->assertOk()
            ->assertExactJson(['data' => ['cookies_set' => true]]);
        $this->assertStringNotContainsString('top-secret-value', $response->getContent());

        $stored = Storage::disk('local')->get('youtube/cookies.txt.enc');
        $this->assertStringNotContainsString('top-secret-value', $stored);
        $this->assertSame(trim(self::COOKIES), Crypt::decryptString($stored));

        $status = $this->getJson('/api/admin/youtube/status')
            ->assertOk()
            ->assertJsonPath('data.cookies_set', true);
        $this->assertStringNotContainsString('top-secret-value', $status->getContent());
    }

    public function test_cookies_that_are_not_netscape_format_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/admin/youtube/cookies', ['cookies' => 'hello world'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cookies']);

        Storage::disk('local')->assertMissing('youtube/cookies.txt.enc');
    }

    public function test_admin_lists_all_users_imports_with_error_detail(): void
    {
        $friend = User::factory()->create(['email' => 'friend@example.com']);
        MediaImport::factory()->for($friend, 'owner')->create([
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => MediaImport::REASON_BLOCKED,
            'error_detail' => 'ERROR: Sign in to confirm you’re not a bot',
            'attempts' => 3,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/imports')
            ->assertOk()
            ->assertJsonPath('data.0.owner.email', 'friend@example.com')
            ->assertJsonPath('data.0.reason_code', 'blocked_by_youtube')
            ->assertJsonPath('data.0.attempts', 3)
            ->assertJsonPath('data.0.error_detail', 'ERROR: Sign in to confirm you’re not a bot');
    }

    public function test_admin_deleting_a_ready_import_removes_its_track_and_files(): void
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

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/imports/{$import->id}")->assertOk();

        $this->assertModelMissing($import);
        $this->assertSoftDeleted($track);
        Storage::disk('media')->assertMissing(['user/1/tracks/a.m4a', 'user/1/covers/c.jpg']);
        $this->assertSame(0, (int) $friend->fresh()->storage_used_bytes);
    }

    public function test_admin_cannot_delete_a_running_import(): void
    {
        $import = MediaImport::factory()->create(['status' => MediaImport::STATUS_DOWNLOADING]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/imports/{$import->id}")->assertUnprocessable();
        $this->assertModelExists($import);
    }

    public function test_non_admin_cannot_hit_youtube_admin_routes(): void
    {
        $import = MediaImport::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/imports')->assertForbidden();
        $this->deleteJson("/api/admin/imports/{$import->id}")->assertForbidden();
        $this->getJson('/api/admin/youtube/status')->assertForbidden();
        $this->putJson('/api/admin/youtube/cookies', ['cookies' => self::COOKIES])->assertForbidden();

        Storage::disk('local')->assertMissing('youtube/cookies.txt.enc');
        $this->assertModelExists($import);
    }
}
