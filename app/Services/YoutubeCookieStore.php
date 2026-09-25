<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

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

        $file = $directory.DIRECTORY_SEPARATOR.self::FILE_NAME;
        file_put_contents($file, Crypt::decryptString((string) $this->disk()->get($this->path())));
        chmod($file, self::FILE_MODE);

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
