<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Admin-provided YouTube cookies.txt, encrypted with APP_KEY on the private
 * "local" disk. Never returned by the API; only decrypted into an import's
 * scratch directory for the duration of one yt-dlp run.
 */
class YoutubeCookieStore
{
    private const FILE_NAME = 'cookies.txt';

    private const FILE_MODE = 0600;

    public function isSet(): bool
    {
        return $this->disk()->exists($this->path());
    }

    public function store(string $contents): void
    {
        $this->disk()->put($this->path(), Crypt::encryptString($contents));
    }

    /**
     * Decrypt into $directory for a single run; null when no cookies are set.
     */
    public function writeTo(string $directory): ?string
    {
        if (! $this->isSet()) {
            return null;
        }

        // tempnam() creates the file 0600 before anything is written; the rename is atomic.
        $file = $directory.DIRECTORY_SEPARATOR.self::FILE_NAME;
        $temp = tempnam($directory, self::FILE_NAME);

        if ($temp === false) {
            throw new RuntimeException('Could not create the YouTube cookies file.');
        }

        chmod($temp, self::FILE_MODE);
        file_put_contents($temp, Crypt::decryptString((string) $this->disk()->get($this->path())));
        rename($temp, $file);

        return $file;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function path(): string
    {
        return (string) config('max-tune.youtube.cookies_path');
    }
}
