<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TrackUploadService
{
    public function __construct(
        private MediaStorage $media,
        private AudioMetadataExtractor $extractor,
    ) {}

    public function upload(User $user, UploadedFile $file): Track
    {
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

        $size = (int) $file->getSize();

        return DB::transaction(function () use ($user, $file, $storagePath, $coverPath, $meta, $size) {
            $track = Track::query()->create([
                'user_id' => $user->id,
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

            $user->increment('storage_used_bytes', $size);

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
