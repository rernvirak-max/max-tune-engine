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

        return DB::transaction(function () use ($user, $file, $storagePath, $coverPath, $meta, $size) {
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
                'title' => $meta['title']
                    ?: (pathinfo($file->getClientOriginalName() ?: 'Untitled', PATHINFO_FILENAME) ?: 'Untitled'),
                'artist_name' => $meta['artist_name'],
                'album_name' => $meta['album_name'],
                'duration_ms' => $meta['duration_ms'],
                'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => $size,
                'storage_path' => $storagePath,
                'cover_path' => $coverPath,
                'visibility' => 'private',
                'source' => 'upload',
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
