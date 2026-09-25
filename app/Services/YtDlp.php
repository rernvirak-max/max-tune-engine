<?php

namespace App\Services;

use App\Exceptions\MediaImportFailedException;
use App\Models\MediaImport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Thin yt-dlp wrapper. Always an argv array (no shell), always a URL rebuilt
 * from a validated video ID, always writing into a per-import scratch dir.
 */
class YtDlp
{
    private const OUTPUT_TEMPLATE = 'media.%(ext)s';

    private const INFO_FILE = 'media.info.json';

    private const THUMBNAIL_FILE = 'media.jpg';

    private const AUDIO_FILE = 'media.m4a';

    private const ERROR_DETAIL_MAX_LENGTH = 2000;

    private const TOO_LARGE_MARKER = 'larger than max-filesize';

    /**
     * yt-dlp error fragments → reason codes, matched against the normalised
     * (lower-cased, straight apostrophes, single spaces) stderr. First match
     * wins, so the order matters:
     * - blocks and rate limits first ("sign in to confirm you're not a bot",
     *   "this content isn't available, try again later", 403/429),
     * - then the specific wordings before the generic "not available".
     * Most texts are YouTube's playability reasons that yt-dlp 2026.08.19
     * passes through (extractor/youtube/_video.py), plus yt-dlp's own
     * geo-restriction and format messages.
     */
    private const ERROR_REASONS = [
        'not a bot' => MediaImport::REASON_BLOCKED,
        'try again later' => MediaImport::REASON_BLOCKED,
        'captcha' => MediaImport::REASON_BLOCKED,
        'http error 403' => MediaImport::REASON_BLOCKED,
        'http error 429' => MediaImport::REASON_BLOCKED,
        'too many requests' => MediaImport::REASON_BLOCKED,
        'confirm your age' => MediaImport::REASON_AGE_RESTRICTED,
        'age-restricted' => MediaImport::REASON_AGE_RESTRICTED,
        'age restricted' => MediaImport::REASON_AGE_RESTRICTED,
        'inappropriate for some users' => MediaImport::REASON_AGE_RESTRICTED,
        'private video' => MediaImport::REASON_PRIVATE,
        'video is private' => MediaImport::REASON_PRIVATE,
        'members-only' => MediaImport::REASON_PRIVATE,
        'members only' => MediaImport::REASON_PRIVATE,
        "channel's members" => MediaImport::REASON_PRIVATE,
        'premium members' => MediaImport::REASON_PRIVATE,
        'live event will begin' => MediaImport::REASON_LIVE,
        'premieres in' => MediaImport::REASON_LIVE,
        'premiere will begin' => MediaImport::REASON_LIVE,
        'live stream recording is not available' => MediaImport::REASON_LIVE,
        'available in your country' => MediaImport::REASON_UNAVAILABLE,
        'blocked it in your country' => MediaImport::REASON_UNAVAILABLE,
        'from your location' => MediaImport::REASON_UNAVAILABLE,
        'geo restriction' => MediaImport::REASON_UNAVAILABLE,
        // A format problem, not the video: keep it retryable instead of "unavailable".
        'requested format is not available' => MediaImport::REASON_UNKNOWN,
        'video unavailable' => MediaImport::REASON_UNAVAILABLE,
        'video is unavailable' => MediaImport::REASON_UNAVAILABLE,
        "content isn't available" => MediaImport::REASON_UNAVAILABLE,
        'no longer available' => MediaImport::REASON_UNAVAILABLE,
        'has been removed' => MediaImport::REASON_UNAVAILABLE,
        'has been terminated' => MediaImport::REASON_UNAVAILABLE,
        'not available' => MediaImport::REASON_UNAVAILABLE,
    ];

    public function __construct(private YoutubeCookieStore $cookies) {}

    /**
     * Fetch metadata + thumbnail only (no audio) so the caller can fail early.
     *
     * @return array{info: array<string, mixed>, info_path: string, thumbnail_path: ?string}
     *
     * @throws MediaImportFailedException
     */
    public function fetchMetadata(string $url, string $workDir, float $timeoutSeconds): array
    {
        $this->run([
            '--skip-download',
            '--write-info-json',
            '--write-thumbnail',
            '--convert-thumbnails', 'jpg',
            '--output', $workDir.DIRECTORY_SEPARATOR.self::OUTPUT_TEMPLATE,
            '--', $url,
        ], $workDir, $timeoutSeconds);

        $infoPath = $workDir.DIRECTORY_SEPARATOR.self::INFO_FILE;
        $thumbnailPath = $workDir.DIRECTORY_SEPARATOR.self::THUMBNAIL_FILE;

        $info = is_file($infoPath) ? json_decode((string) file_get_contents($infoPath), true) : null;

        if (! is_array($info)) {
            throw new MediaImportFailedException(MediaImport::REASON_UNKNOWN, 'yt-dlp wrote no readable metadata.');
        }

        return [
            'info' => $info,
            'info_path' => $infoPath,
            'thumbnail_path' => is_file($thumbnailPath) ? $thumbnailPath : null,
        ];
    }

    /**
     * Download audio only as m4a. AAC sources are copied as-is (no re-encode);
     * anything else is converted by ffmpeg.
     *
     * @throws MediaImportFailedException
     */
    public function downloadAudio(string $infoPath, string $workDir, int $maxBytes, float $timeoutSeconds): string
    {
        $process = $this->run([
            '--load-info-json', $infoPath,
            '--format', 'bestaudio[ext=m4a]/bestaudio',
            '--extract-audio',
            '--audio-format', 'm4a',
            '--max-filesize', (string) $maxBytes,
            '--output', $workDir.DIRECTORY_SEPARATOR.self::OUTPUT_TEMPLATE,
        ], $workDir, $timeoutSeconds);

        $audioPath = $workDir.DIRECTORY_SEPARATOR.self::AUDIO_FILE;

        if (is_file($audioPath)) {
            return $audioPath;
        }

        // yt-dlp skips oversize files and still exits 0.
        $output = $process->getOutput().$process->getErrorOutput();
        $reason = str_contains($output, self::TOO_LARGE_MARKER)
            ? MediaImport::REASON_TOO_LARGE
            : MediaImport::REASON_UNKNOWN;

        throw new MediaImportFailedException($reason, $this->detail($output));
    }

    /**
     * Installed yt-dlp version, or null when the binary is missing/broken.
     */
    public function version(): ?string
    {
        $process = new Process([$this->binary(), '--version']);
        $process->setTimeout((float) config('max-tune.youtube.version_timeout_seconds'));

        try {
            $process->run();
        } catch (ProcessRuntimeException) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws MediaImportFailedException
     */
    private function run(array $arguments, string $workDir, float $timeoutSeconds): Process
    {
        $process = new Process([...$this->baseArguments($workDir), ...$arguments], $workDir);
        $process->setTimeout(max($timeoutSeconds, 1));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->killStragglers($workDir);

            throw new MediaImportFailedException(MediaImport::REASON_TIMEOUT, $e->getMessage());
        } catch (ProcessRuntimeException $e) {
            throw new MediaImportFailedException(MediaImport::REASON_UNKNOWN, $e->getMessage());
        }

        if (! $process->isSuccessful()) {
            $stderr = $process->getErrorOutput();

            throw new MediaImportFailedException($this->reasonFor($stderr), $this->detail($stderr));
        }

        return $process;
    }

    /**
     * @return list<string>
     */
    private function baseArguments(string $workDir): array
    {
        $arguments = [$this->binary(), '--ignore-config', '--no-playlist', '--no-progress'];

        if ($ffmpeg = config('max-tune.youtube.ffmpeg_binary')) {
            array_push($arguments, '--ffmpeg-location', (string) $ffmpeg);
        }

        if ($runtimes = config('max-tune.youtube.js_runtimes')) {
            array_push($arguments, '--js-runtimes', (string) $runtimes);
        }

        if ($cookieFile = $this->cookies->writeTo($workDir)) {
            array_push($arguments, '--cookies', $cookieFile);
        }

        return $arguments;
    }

    /**
     * Best effort after a timeout: Symfony kills yt-dlp itself, but its ffmpeg
     * child (already re-parented by then) would keep running. Only processes
     * working on this import have its scratch dir in their argv, so match that
     * path as a plain substring of /proc/<pid>/cmdline: no regex to escape and
     * no dependency on procps/pkill.
     */
    private function killStragglers(string $workDir): void
    {
        $needle = rtrim($workDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $cmdlines = glob('/proc/[0-9]*/cmdline') ?: [];

        if ($cmdlines === []) {
            Log::warning('yt-dlp timed out; /proc is unavailable, so child processes were not cleaned up.', ['work_dir' => $workDir]);

            return;
        }

        foreach ($cmdlines as $file) {
            $pid = (int) basename(dirname($file));
            $cmdline = @file_get_contents($file);

            if ($pid === getmypid() || $cmdline === false || ! str_contains($cmdline, $needle)) {
                continue;
            }

            // SIGKILL comes from ext-pcntl (which the queue worker needs for timeouts anyway).
            if (function_exists('posix_kill') && defined('SIGKILL')) {
                @posix_kill($pid, SIGKILL);
            } else {
                (new Process(['kill', '-KILL', (string) $pid]))->run();
            }
        }
    }

    private function binary(): string
    {
        return (string) config('max-tune.youtube.ytdlp_binary');
    }

    private function reasonFor(string $stderr): string
    {
        // Case-insensitive, and robust to curly apostrophes and wrapped/extra whitespace.
        $haystack = (string) preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '`'], "'", Str::lower($stderr)));

        foreach (self::ERROR_REASONS as $fragment => $reason) {
            if (str_contains($haystack, $fragment)) {
                return $reason;
            }
        }

        return MediaImport::REASON_UNKNOWN;
    }

    /**
     * Admin-only detail: the ERROR lines if any, else the raw output (truncated).
     */
    private function detail(string $output): string
    {
        $errors = collect(preg_split('/\R/', $output) ?: [])
            ->filter(fn (string $line) => str_starts_with($line, 'ERROR:'))
            ->implode("\n");

        return Str::limit(trim($errors !== '' ? $errors : $output), self::ERROR_DETAIL_MAX_LENGTH);
    }
}
