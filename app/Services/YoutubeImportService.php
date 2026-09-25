<?php

namespace App\Services;

use App\Exceptions\ImportRejectedException;
use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Submit / retry / discard YouTube imports. Every check runs before anything
 * is queued; the download itself happens in ProcessYoutubeImport.
 */
class YoutubeImportService
{
    public function __construct(
        private YoutubeUrl $urls,
        private MediaStorage $media,
    ) {}

    /**
     * @throws ImportRejectedException
     */
    public function submit(User $user, string $url): MediaImport
    {
        $videoId = $this->urls->videoId($url);

        $import = DB::transaction(function () use ($user, $videoId) {
            $this->admit($this->lockUser($user), $videoId);

            return $user->mediaImports()->create([
                'url' => $this->urls->watchUrl($videoId),
                'video_id' => $videoId,
                'status' => MediaImport::STATUS_QUEUED,
            ]);
        });

        ProcessYoutubeImport::dispatch($import);

        return $import;
    }

    /**
     * Re-queue a failed import with the same checks as a new submission.
     *
     * @throws ImportRejectedException
     */
    public function retry(MediaImport $import): MediaImport
    {
        if ($import->status !== MediaImport::STATUS_FAILED) {
            throw ImportRejectedException::notAllowed('Only failed imports can be retried.');
        }

        DB::transaction(function () use ($import) {
            $this->admit($this->lockUser($import->owner), $import->video_id, $import);

            $import->update([
                'status' => MediaImport::STATUS_QUEUED,
                'reason_code' => null,
                'error_detail' => null,
                'attempts' => 0,
            ]);
        });

        ProcessYoutubeImport::dispatch($import);

        return $import;
    }

    /**
     * Delete an import that no worker holds, with its thumbnail and scratch files.
     *
     * @throws ImportRejectedException while downloading/processing (no cancel in v1)
     */
    public function discard(MediaImport $import): void
    {
        if ($import->isRunning()) {
            throw ImportRejectedException::notAllowed('Can\'t cancel while downloading.');
        }

        $this->purge($import);
    }

    /**
     * Disabled users can't import: drop whatever they still have waiting.
     */
    public function cancelQueued(User $user): void
    {
        $user->mediaImports()
            ->where('status', MediaImport::STATUS_QUEUED)
            ->get()
            ->each(fn (MediaImport $import) => $this->purge($import));
    }

    private function purge(MediaImport $import): void
    {
        // A ready import's thumbnail is its track's cover; the track owns it.
        if ($import->status !== MediaImport::STATUS_READY) {
            $this->media->delete($import->thumbnail_path);
        }

        File::deleteDirectory($import->workDir());
        $import->delete();
    }

    private function lockUser(User $user): User
    {
        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Duplicate, quota, concurrency and rate checks, in that order, so a
     * refused request never burns a rate-limit slot.
     *
     * @throws ImportRejectedException
     */
    private function admit(User $user, string $videoId, ?MediaImport $retrying = null): void
    {
        $existingTrackId = $user->tracks()
            ->where('source', MediaImport::TRACK_SOURCE)
            ->where('source_id', $videoId)
            ->value('id');

        if ($existingTrackId !== null) {
            throw ImportRejectedException::duplicate('track', (int) $existingTrackId);
        }

        $active = $user->mediaImports()->active()->when($retrying, fn ($q) => $q->whereKeyNot($retrying->id));
        $existingImportId = (clone $active)->where('video_id', $videoId)->value('id');

        if ($existingImportId !== null) {
            throw ImportRejectedException::duplicate('import', (int) $existingImportId);
        }

        if ($user->storageRemainingBytes() <= 0) {
            throw ImportRejectedException::quotaExceeded();
        }

        $maxActive = (int) config('max-tune.youtube.max_active_per_user');

        if ($active->count() >= $maxActive) {
            throw ImportRejectedException::tooManyActive($maxActive);
        }

        $key = 'youtube-imports:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('max-tune.youtube.rate_limit'))) {
            throw ImportRejectedException::rateLimited(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, (int) config('max-tune.youtube.rate_decay_seconds'));
    }
}
