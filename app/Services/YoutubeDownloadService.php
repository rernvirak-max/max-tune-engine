<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class YoutubeDownloadService
{
    /**
     * @return array{id: ?string, title: ?string, uploader: ?string, duration: ?int}
     */
    public function fetchMetadata(string $url): array
    {
        $result = Process::timeout(120)
            ->run([
                $this->binary(),
                '--dump-single-json',
                '--no-playlist',
                '--no-warnings',
                $url,
            ]);

        if ($result->failed()) {
            throw new RuntimeException($this->friendlyError($result->errorOutput() ?: $result->output()));
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($result->output(), true);
        if (! is_array($json)) {
            throw new RuntimeException('Could not read video metadata from YouTube.');
        }

        return [
            'id' => isset($json['id']) ? (string) $json['id'] : null,
            'title' => isset($json['title']) ? (string) $json['title'] : null,
            'uploader' => isset($json['uploader']) ? (string) $json['uploader'] : (isset($json['channel']) ? (string) $json['channel'] : null),
            'duration' => isset($json['duration']) ? (int) $json['duration'] : null,
        ];
    }

    /**
     * Download best audio and convert to mp3. Returns absolute path to the file.
     */
    public function downloadAudio(string $url, string $outputDirectory): string
    {
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Could not create download directory.');
        }

        $template = rtrim($outputDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'audio.%(ext)s';

        $result = Process::timeout((int) config('max-tune.youtube.download_timeout', 600))
            ->run([
                $this->binary(),
                '-x',
                '--audio-format', 'mp3',
                '--audio-quality', '0',
                '--no-playlist',
                '--no-warnings',
                '-o', $template,
                $url,
            ]);

        if ($result->failed()) {
            throw new RuntimeException($this->friendlyError($result->errorOutput() ?: $result->output()));
        }

        $matches = glob($outputDirectory.DIRECTORY_SEPARATOR.'audio.*') ?: [];
        $matches = array_values(array_filter($matches, 'is_file'));

        if ($matches === []) {
            throw new RuntimeException('Download finished but no audio file was found.');
        }

        return $matches[0];
    }

    public function extractVideoId(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    public function isSupportedUrl(string $url): bool
    {
        return $this->extractVideoId($url) !== null
            || Str::contains(Str::lower($url), ['youtube.com', 'youtu.be']);
    }

    private function binary(): string
    {
        return (string) config('max-tune.youtube.binary', 'yt-dlp');
    }

    private function friendlyError(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 'YouTube download failed.';
        }

        if (Str::contains(Str::lower($raw), ['not found', 'no such file', 'yt-dlp'])) {
            return 'yt-dlp is not installed on the server. Install yt-dlp and ffmpeg, then retry.';
        }

        $first = strtok($raw, "\n") ?: $raw;

        return Str::limit($first, 240);
    }
}
