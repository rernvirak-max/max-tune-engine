<?php

namespace Tests\Feature;

use App\Exceptions\MediaImportFailedException;
use App\Models\MediaImport;
use App\Services\YtDlp;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Real stderr of yt-dlp 2026.08.19 through the real Process path (stub binary):
 * YouTube's playability reasons passed through by extractor/youtube/_video.py
 * (which appends the cookies hint to "sign in" reasons), plus yt-dlp's own
 * geo-restriction, format and download errors.
 */
class YtDlpErrorWordingTest extends TestCase
{
    private const ID = 'ERROR: [youtube] Ex4mpleVid0: ';

    private const COOKIES_HINT = 'Use --cookies-from-browser or --cookies for the authentication. See  https://github.com/yt-dlp/yt-dlp/wiki/FAQ#how-do-i-pass-cookies-to-yt-dlp  for how to manually pass cookies. Also see  https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies  for tips on effectively exporting YouTube cookies';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->root = storage_path('framework/testing/yt-dlp-wording-'.uniqid());
        File::ensureDirectoryExists($this->root.'/work');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function currentYtDlpStderr(): array
    {
        $id = self::ID;
        $hint = self::COOKIES_HINT;

        return [
            '403 during audio download' => ['ERROR: unable to download video data: HTTP Error 403: Forbidden', MediaImport::REASON_BLOCKED],
            'bot check + hint' => [$id.'Sign in to confirm you’re not a bot. '.$hint, MediaImport::REASON_BLOCKED],
            'session rate limited' => [$id.'This content isn’t available, try again later. The current session has been rate-limited by YouTube for up to an hour.', MediaImport::REASON_BLOCKED],
            'captcha' => [$id.'Video unavailable. YouTube is requiring a captcha challenge before playback', MediaImport::REASON_BLOCKED],
            'this video is unavailable' => [$id.'This video is unavailable', MediaImport::REASON_UNAVAILABLE],
            'content isn’t available' => [$id.'Video unavailable. This content isn’t available.', MediaImport::REASON_UNAVAILABLE],
            'upper case' => [$id.'VIDEO UNAVAILABLE', MediaImport::REASON_UNAVAILABLE],
            'wrapped whitespace' => [$id."This video\n   is unavailable", MediaImport::REASON_UNAVAILABLE],
            'removed by uploader' => [$id.'Video unavailable. This video has been removed by the uploader', MediaImport::REASON_UNAVAILABLE],
            'account terminated' => [$id.'Video unavailable. This video is no longer available because the YouTube account associated with this video has been terminated.', MediaImport::REASON_UNAVAILABLE],
            'private + hint' => [$id.'Private video. Sign in if you\'ve been granted access to this video. '.$hint, MediaImport::REASON_PRIVATE],
            'this video is private' => [$id.'This video is private', MediaImport::REASON_PRIVATE],
            'members only' => [$id.'Join this channel to get access to members-only content like this video, and other exclusive perks.', MediaImport::REASON_PRIVATE],
            'members level' => [$id.'This video is available to this channel\'s members on level: Supporter (or any higher level). Join this channel to get access to members-only content and other exclusive perks.', MediaImport::REASON_PRIVATE],
            'music premium' => [$id.'This video is only available to Music Premium members', MediaImport::REASON_PRIVATE],
            'age gate + hint' => [$id.'Sign in to confirm your age. This video may be inappropriate for some users. '.$hint, MediaImport::REASON_AGE_RESTRICTED],
            'region (uploader)' => [$id.'The uploader has not made this video available in your country.', MediaImport::REASON_UNAVAILABLE],
            'region (yt-dlp default)' => [$id.'This video is not available from your location due to geo restriction', MediaImport::REASON_UNAVAILABLE],
            'region (copyright)' => [$id.'Video unavailable. This video contains content from Example Records, who has blocked it in your country on copyright grounds.', MediaImport::REASON_UNAVAILABLE],
            'live soon' => [$id.'This live event will begin in a few moments.', MediaImport::REASON_LIVE],
            'live in hours' => [$id.'This live event will begin in 3 hours.', MediaImport::REASON_LIVE],
            'premiere' => [$id.'Premiere will begin shortly', MediaImport::REASON_LIVE],
            'premieres in' => [$id.'Premieres in 5 hours', MediaImport::REASON_LIVE],
            'format, not the video' => [$id.'Requested format is not available. Use --list-formats for a list of available formats', MediaImport::REASON_UNKNOWN],
        ];
    }

    #[DataProvider('currentYtDlpStderr')]
    public function test_current_yt_dlp_wording_maps_to_the_right_reason(string $stderr, string $reason): void
    {
        $stub = $this->root.'/yt-dlp';
        file_put_contents($stub, "#!/bin/sh\necho ".escapeshellarg($stderr)." >&2\nexit 1\n");
        chmod($stub, 0755);
        Config::set('max-tune.youtube.ytdlp_binary', $stub);

        try {
            app(YtDlp::class)->downloadAudio($this->root.'/work/media.info.json', $this->root.'/work', 52428800, 10);
            $this->fail('Expected a MediaImportFailedException.');
        } catch (MediaImportFailedException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
