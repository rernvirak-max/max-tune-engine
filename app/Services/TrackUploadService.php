<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TrackUploadService
{
    private const QUOTA_MESSAGE = 'Storage full · Delete tracks or ask for more space';

    public function __construct(
        private MediaStorage $media,
        private AudioMetadataExtractor $extractor,
    ) {}

    public function upload(User $user, UploadedFile $file): Track
    {
        $size = (int) $file->getSize();

        if ($size > $user->storageRemainingBytes()) {
            throw ValidationException::withMessages([
                'file' => [self::QUOTA_MESSAGE],
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

        try {
            return $this->createWithinQuota($user, [
                'title' => $meta['title']
                    ?: (pathinfo($file->getClientOriginalName() ?: 'Untitled', PATHINFO_FILENAME) ?: 'Untitled'),
                'artist_name' => $meta['artist_name'],
                'album_name' => $meta['album_name'],
                'duration_ms' => $meta['duration_ms'],
                'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => $size,
                'storage_path' => $storagePath,
                'cover_path' => $coverPath,
                'source' => 'upload',
            ]);
        } catch (Throwable $e) {
            $this->media->delete($storagePath);
            $this->media->delete($coverPath);

            throw $e;
        }
    }

    /**
     * Create a private stored track and charge its size to the owner's quota
     * under a row lock. Callers own the media files and delete them on failure.
     *
     * @param  array<string, mixed>  $attributes  must include size
     *
     * @throws ValidationException when the track no longer fits the quota
     */
    public function createWithinQuota(User $user, array $attributes): Track
    {
        return DB::transaction(function () use ($user, $attributes) {
            /** @var User|null $locked */
            $locked = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('User not found during quota check.');
            }

            $size = (int) $attributes['size'];

            if ($size > $locked->storageRemainingBytes()) {
                throw ValidationException::withMessages([
                    'file' => [self::QUOTA_MESSAGE],
                ]);
            }

            $track = Track::query()->create([
                ...$attributes,
                'user_id' => $locked->id,
                'visibility' => 'private',
                'import_mode' => 'stored',
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