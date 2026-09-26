<?php

namespace Tests\Feature;

use App\Exceptions\MediaImportFailedException;
use App\Models\MediaImport;
use App\Services\YoutubeCookieStore;
use App\Services\YtDlp;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs YtDlp against a stub executable, so the real Symfony Process path
 * (argv array, exit codes, stderr mapping) is exercised without the network.
 */
class YtDlpProcessTest extends TestCase
{
    private string $root;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->root = storage_path('framework/testing/yt-dlp-'.uniqid());
        // Regex metacharacters in the path: straggler cleanup must match it literally.
        $this->workDir = $this->root.'/work+(1).[x]';
        File::ensureDirectoryExists($this->workDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function stderrReasons(): array
    {
        return [
            'bot check' => ['ERROR: [youtube] Ex4mpleVid0: Sign in to confirm you’re not a bot. Use --cookies', MediaImport::REASON_BLOCKED],
            'rate limited' => ['ERROR: unable to download webpage: HTTP Error 429: Too Many Requests', MediaImport::REASON_BLOCKED],
            'age gate' => ['ERROR: [youtube] Ex4mpleVid0: Sign in to confirm your age. This video may be inappropriate for some users.', MediaImport::REASON_AGE_RESTRICTED],
            'private' => ['ERROR: [youtube] Ex4mpleVid0: Private video. Sign in if you\'ve been granted access to this video', MediaImport::REASON_PRIVATE],
            'members only' => ['ERROR: [youtube] Ex4mpleVid0: Join this channel to get access to members-only content like this video', MediaImport::REASON_PRIVATE],
            'premiere' => ['ERROR: [youtube] Ex4mpleVid0: Premieres in 3 hours', MediaImport::REASON_LIVE],
            'region' => ['ERROR: [youtube] Ex4mpleVid0: The uploader has not made this video available in your country', MediaImport::REASON_UNAVAILABLE],
            'removed' => ['ERROR: [youtube] Ex4mpleVid0: Video unavailable. This video has been removed by the uploader', MediaImport::REASON_UNAVAILABLE],
            'anything else' => ['ERROR: something new broke', MediaImport::REASON_UNKNOWN],
        ];
    }

    #[DataProvider('stderrReasons')]
    public function test_failed_run_maps_stderr_to_reason_code(string $stderr, string $reason): void
    {
        $this->useStub('echo '.escapeshellarg($stderr).' >&2; exit 1');

        try {
            app(YtDlp::class)->fetchMetadata('https://www.youtube.com/watch?v=Ex4mpleVid0', $this->workDir, 10);
            $this->fail('Expected a MediaImportFailedException.');
        } catch (MediaImportFailedException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($stderr, $e->detail);
        }
    }

    public function test_metadata_run_passes_an_argv_array_and_reads_info_and_thumbnail(): void
    {
        $this->useStub(<<<'SH'
printf '%s\n' "$@" > "$PWD/argv.txt"
printf '{"id":"Ex4mpleVid0","title":"T; rm -rf /"}' > "$PWD/media.info.json"
printf 'jpg' > "$PWD/media.jpg"
SH);

        $result = app(YtDlp::class)->fetchMetadata('https://www.youtube.com/watch?v=Ex4mpleVid0', $this->workDir, 10);

        $this->assertSame('T; rm -rf /', $result['info']['title']);
        $this->assertSame($this->workDir.'/media.jpg', $result['thumbnail_path']);

        $argv = file($this->workDir.'/argv.txt', FILE_IGNORE_NEW_LINES);
        $this->assertContains('--skip-download', $argv);
        $this->assertContains('--no-playlist', $argv);
        $this->assertNotContains('--cookies', $argv);
        $this->assertSame(['--', 'https://www.youtube.com/watch?v=Ex4mpleVid0'], array_slice($argv, -2));
    }

    public function test_cookies_are_decrypted_into_the_work_dir_only_when_set(): void
    {
        app(YoutubeCookieStore::class)->store(".youtube.com\tTRUE\t/\tTRUE\t0\tSID\tsecret");
        $this->useStub(<<<'SH'
printf '%s\n' "$@" > "$PWD/argv.txt"
printf '{}' > "$PWD/media.info.json"
SH);

        app(YtDlp::class)->fetchMetadata('https://www.youtube.com/watch?v=Ex4mpleVid0', $this->workDir, 10);

        $argv = file($this->workDir.'/argv.txt', FILE_IGNORE_NEW_LINES);
        $cookieFile = $argv[array_search('--cookies', $argv, true) + 1];
        $this->assertSame($this->workDir.'/cookies.txt', $cookieFile);
        $this->assertStringContainsString("SID\tsecret", (string) file_get_contents($cookieFile));
        $this->assertSame(0600, fileperms($cookieFile) & 0777);
        $this->assertSame(['argv.txt', 'cookies.txt', 'media.info.json'], array_map('basename', File::files($this->workDir)));
    }

    public function test_download_skipped_for_max_filesize_is_too_large(): void
    {
        $this->useStub('echo "[download] File is larger than max-filesize (60000000 bytes > 52428800 bytes). Aborting."');

        try {
            app(YtDlp::class)->downloadAudio($this->workDir.'/media.info.json', $this->workDir, 52428800, 10);
            $this->fail('Expected a MediaImportFailedException.');
        } catch (MediaImportFailedException $e) {
            $this->assertSame(MediaImport::REASON_TOO_LARGE, $e->reason);
        }
    }

    public function test_slow_process_times_out_and_its_child_is_killed(): void
    {
        // Like yt-dlp running ffmpeg: a child whose argv names a file in the scratch dir.
        $this->useStub(<<<'SH'
sh -c 'sleep 30; :' "$PWD/media.m4a" &
echo $! > "$PWD/child.pid"
wait
SH);

        try {
            app(YtDlp::class)->fetchMetadata('https://www.youtube.com/watch?v=Ex4mpleVid0', $this->workDir, 1);
            $this->fail('Expected a timeout.');
        } catch (MediaImportFailedException $e) {
            $this->assertSame(MediaImport::REASON_TIMEOUT, $e->reason);
        }

        $childPid = (int) file_get_contents($this->workDir.'/child.pid');
        $this->assertFalse($this->isAlive($childPid), 'The child process outlived the timed-out yt-dlp.');
    }

    public function test_version_is_reported_or_null_when_binary_is_missing(): void
    {
        $this->useStub('echo 2026.08.19');
        $this->assertSame('2026.08.19', app(YtDlp::class)->version());

        Config::set('max-tune.youtube.ytdlp_binary', $this->root.'/missing-yt-dlp');
        $this->assertNull(app(YtDlp::class)->version());
    }

    /**
     * Running and not a zombie (an orphan may wait for its reaper briefly).
     */
    private function isAlive(int $pid): bool
    {
        for ($i = 0; $i < 20; $i++) {
            $status = @file_get_contents("/proc/{$pid}/stat");

            if ($status === false || preg_match('/\) [ZX] /', $status)) {
                return false;
            }

            usleep(50_000);
        }

        return true;
    }

    private function useStub(string $body): void
    {
        $stub = $this->root.'/yt-dlp';
        file_put_contents($stub, "#!/bin/sh\n{$body}\n");
        chmod($stub, 0755);
        Config::set('max-tune.youtube.ytdlp_binary', $stub);
    }
}
