<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TrackUploadService
{
    public function __construct(
        private MediaStorage $media,
        private AudioMetadataExtractor $extractor,
    ) {}

    public function upload(User $user, UploadedFile $file): Track
    {
        $size = (int) $file->getSize();

        if ($size > $user->storageRemainingBytes()) {
            throw ValidationException::withMessages([
                'file' => ['Storage full · Delete tracks or ask for more space'],
            ]);
        }

        $storagePath = $this->media->storeTrackAudio($user->id, $file);
        $absolute = $this->media->absolutePath($storagePath);

        if ($absolute === null) {
            $this->media->delete($storagePath);
            throw new RuntimeException('Media disk does not support local metadata extraction.');
        }

        $meta = $this->extractor->extract($absolute);
        $coverPath = null;

        if ($meta['cover_binary']) {
            $ext = $this->extensionFromMime($meta['cover_mime'] ?? 'image/jpeg');
            $coverPath = $this->media->storeCover($user->id, $meta['cover_binary'], $ext);
        }

        return $this->createStoredTrack(
            user: $user,
            storagePath: $storagePath,
            coverPath: $coverPath,
            meta: $meta,
            size: $size,
            mime: $file->getMimeType() ?: $file->getClientMimeType() ?: 'audio/mpeg',
            fallbackTitle: pathinfo($file->getClientOriginalName() ?: 'Untitled', PATHINFO_FILENAME) ?: 'Untitled',
            source: 'upload',
            externalId: null,
        );
    }

    /**
     * Import an already-downloaded local audio file into the user's library.
     *
     * @param  array{title?: ?string, artist_name?: ?string, album_name?: ?string, external_id?: ?string, source?: string}  $overrides
     */
    public function importFromPath(User $user, string $absolutePath, array $overrides = []): Track
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Downloaded audio file is missing.');
        }

        $size = (int) filesize($absolutePath);

        if ($size > $user->storageRemainingBytes()) {
            throw ValidationException::withMessages([
                'file' => ['Storage full · Delete tracks or ask for more space'],
            ]);
        }

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'mp3');
        $storagePath = sprintf('user/%d/tracks/%s.%s', $user->id, (string) Str::uuid(), $ext);

        $binary = file_get_contents($absolutePath);
        if ($binary === false) {
            throw new RuntimeException('Could not read downloaded audio file.');
        }

        $this->media->disk()->put($storagePath, $binary);
        $storedAbsolute = $this->media->absolutePath($storagePath);

        if ($storedAbsolute === null) {
            $this->media->delete($storagePath);
            throw new RuntimeException('Media disk does not support local metadata extraction.');
        }

        $meta = $this->extractor->extract($storedAbsolute);
        $coverPath = null;

        if ($meta['cover_binary']) {
            $extCover = $this->extensionFromMime($meta['cover_mime'] ?? 'image/jpeg');
            $coverPath = $this->media->storeCover($user->id, $meta['cover_binary'], $extCover);
        }

        $mime = match ($ext) {
            'm4a', 'mp4' => 'audio/mp4',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            default => 'audio/mpeg',
        };

        return $this->createStoredTrack(
            user: $user,
            storagePath: $storagePath,
            coverPath: $coverPath,
            meta: [
                'title' => $overrides['title'] ?? $meta['title'],
                'artist_name' => $overrides['artist_name'] ?? $meta['artist_name'],
                'album_name' => $overrides['album_name'] ?? $meta['album_name'],
                'duration_ms' => $meta['duration_ms'],
            ],
            size: $size,
            mime: $mime,
            fallbackTitle: $overrides['title'] ?? (pathinfo($absolutePath, PATHINFO_FILENAME) ?: 'Untitled'),
            source: $overrides['source'] ?? 'youtube',
            externalId: $overrides['external_id'] ?? null,
        );
    }

    /**
     * @param  array{title: ?string, artist_name: ?string, album_name: ?string, duration_ms: ?int}  $meta
     */
    private function createStoredTrack(
        User $user,
        string $storagePath,
        ?string $coverPath,
        array $meta,
        int $size,
        string $mime,
        string $fallbackTitle,
        string $source,
        ?string $externalId,
    ): Track {
        return DB::transaction(function () use ($user, $storagePath, $coverPath, $meta, $size, $mime, $fallbackTitle, $source, $externalId) {
            /** @var User|null $locked */
            $locked = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                $this->media->delete($storagePath);
                if ($coverPath) {
                    $this->media->delete($coverPath);
                }
                throw new RuntimeException('User not found during quota check.');
            }

            if ($size > $locked->storageRemainingBytes()) {
                $this->media->delete($storagePath);
                if ($coverPath) {
                    $this->media->delete($coverPath);
                }
                throw ValidationException::withMessages([
                    'file' => ['Storage full · Delete tracks or ask for more space'],
                ]);
            }

            $track = Track::query()->create([
                'user_id' => $locked->id,
                'title' => $meta['title'] ?: $fallbackTitle,
                'artist_name' => $meta['artist_name'],
                'album_name' => $meta['album_name'],
                'duration_ms' => $meta['duration_ms'],
                'mime' => $mime,
                'size' => $size,
                'storage_path' => $storagePath,
                'cover_path' => $coverPath,
                'visibility' => 'private',
                'source' => $source,
                'external_id' => $externalId,
                'import_mode' => 'stored',
                'imported_by' => $locked->id,
            ]);

            $locked->increment('storage_used_bytes', $size);

            return $track->fresh();
        });
    }

    private function extensionFromMime(string $mime): string
    {
        return match (Str::lower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}