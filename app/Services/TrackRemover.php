<?php

namespace App\Services;

use App\Models\MediaImport;
use App\Models\Track;

class TrackRemover
{
    public function __construct(private MediaStorage $media) {}

    /**
     * Delete a track's files, soft-delete the row and give its bytes back to
     * the owner's quota. Shared by owner delete, admin removal and admin
     * import deletion.
     */
    public function remove(Track $track): void
    {
        $owner = $track->owner;
        $size = (int) $track->size;

        $this->media->delete($track->storage_path);
        $this->media->delete($track->cover_path);
        $track->delete();

        // Tracks are soft-deleted, so the FK's nullOnDelete never fires. Unlink the
        // import that made this track; its thumbnail was the cover deleted above,
        // so the admin list shows the placeholder instead of a broken image.
        MediaImport::query()->where('track_id', $track->id)->update([
            'track_id' => null,
            'thumbnail_path' => null,
        ]);

        if ($owner && $size > 0) {
            $owner->storage_used_bytes = max(0, (int) $owner->storage_used_bytes - $size);
            $owner->save();
        }
    }
}
