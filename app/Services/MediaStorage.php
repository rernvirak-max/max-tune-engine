<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Storage abstraction: local media disk now, S3/R2 later via MEDIA_DISK.
 */
class MediaStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('max-tune.media_disk', 'media'));
    }

    public function diskName(): string
    {
        return (string) config('max-tune.media_disk', 'media');
    }

    /**
     * Store an uploaded audio file under user/{id}/tracks/{uuid}.ext
     */
    public function storeTrackAudio(int $userId, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = sprintf('user/%d/tracks/%s.%s', $userId, (string) Str::uuid(), $ext);

        $this->disk()->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        return $path;
    }

    public function storeCover(int $userId, string $binary, string $extension = 'jpg'): string
    {
        $path = sprintf('user/%d/covers/%s.%s', $userId, (string) Str::uuid(), $extension);
        $this->disk()->put($path, $binary);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $this->disk()->exists($path);
    }

    public function size(?string $path): int
    {
        if (! $path || ! $this->exists($path)) {
            return 0;
        }

        return (int) $this->disk()->size($path);
    }

    /**
     * Absolute path when using local disk (for streaming / getID3 later).
     */
    public function absolutePath(string $path): ?string
    {
        $disk = $this->disk();

        if (! method_exists($disk, 'path')) {
            return null;
        }

        return $disk->path($path);
    }
}
