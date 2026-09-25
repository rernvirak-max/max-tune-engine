<?php

namespace App\Services;

use App\Exceptions\MediaImportFailedException;
use App\Models\MediaImport;
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
     * Lower-cased yt-dlp error fragments → reason codes. First match wins, so
     * bot checks come before the generic "sign in" private-video wording.
     */
    private const ERROR_REASONS = [
        'not a bot' => MediaImport::REASON_BLOCKED,
        'http error 429' => MediaImport::REASON_BLOCKED,
        'too many requests' => MediaImport::REASON_BLOCKED,
        'confirm your age' => MediaImport::REASON_AGE_RESTRICTED,
        'age-restricted' => MediaImport::REASON_AGE_RESTRICTED,
        'private video' => MediaImport::REASON_PRIVATE,
        'members-only' => MediaImport::REASON_PRIVATE,
        'live event will begin' => MediaImport::REASON_LIVE,
        'premieres in' => MediaImport::REASON_LIVE,
        'video unavailable' => MediaImport::REASON_UNAVAILABLE,
        'not available' => MediaImport::REASON_UNAVAILABLE,
        'available in your country' => MediaImport::REASON_UNAVAILABLE,
        'has been removed' => MediaImport::REASON_UNAVAILABLE,
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
     * child would keep running. Only processes working on this import have
     * its scratch dir in their argv.
     */
    private function killStragglers(string $workDir): void
    {
        (new Process(['pkill', '-KILL', '-f', preg_quote($workDir.DIRECTORY_SEPARATOR)]))->run();
    }

    private function binary(): string
    {
        return (string) config('max-tune.youtube.ytdlp_binary');
    }

    private function reasonFor(string $stderr): string
    {
        $haystack = Str::lower($stderr);

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
