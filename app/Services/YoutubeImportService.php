<?php

namespace App\Services;

use App\Exceptions\ImportRejectedException;
use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\Track;
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
     * Re-queue a failed import with the same checks as a new submission. The
     * row is locked and re-checked so two concurrent retries queue it once.
     *
     * @throws ImportRejectedException
     */
    public function retry(MediaImport $import): MediaImport
    {
        DB::transaction(function () use ($import) {
            $locked = $this->lockImport($import);

            if ($locked?->status !== MediaImport::STATUS_FAILED) {
                throw ImportRejectedException::notAllowed('Only failed imports can be retried.');
            }

            $this->admit($this->lockUser($locked->owner), $locked->video_id, $locked);

            $locked->update([
                'status' => MediaImport::STATUS_QUEUED,
                'reason_code' => null,
                'error_detail' => null,
                'attempts' => 0,
            ]);

            $import->setRawAttributes($locked->getAttributes(), true);
        });

        ProcessYoutubeImport::dispatch($import);

        return $import;
    }

    /**
     * Delete an import that no worker holds, with its thumbnail and scratch
     * files. Locked and re-checked so a worker can't claim it mid-delete.
     * Returns the track of a ready import (the caller decides whether it goes too).
     *
     * @throws ImportRejectedException while downloading/processing (no cancel in v1)
     */
    public function discard(MediaImport $import): ?Track
    {
        return DB::transaction(function () use ($import) {
            $locked = $this->lockImport($import);

            if ($locked === null) {
                return null;
            }

            if ($locked->isRunning()) {
                throw ImportRejectedException::notAllowed('Can\'t cancel while downloading.');
            }

            $track = $locked->track;
            $this->purge($locked);

            return $track;
        });
    }

    /**
     * Delete failed imports (and their thumbnails) last touched before $cutoff.
     * Each row is locked and re-checked, so one retried meanwhile is kept.
     */
    public function expireFailed(\DateTimeInterface $cutoff): int
    {
        $expired = 0;

        MediaImport::query()
            ->where('status', MediaImport::STATUS_FAILED)
            ->where('updated_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $id) use ($cutoff, &$expired) {
                DB::transaction(function () use ($id, $cutoff, &$expired) {
                    $locked = MediaImport::query()->whereKey($id)->lockForUpdate()->first();

                    if ($locked?->status === MediaImport::STATUS_FAILED && $locked->updated_at < $cutoff) {
                        $this->purge($locked);
                        $expired++;
                    }
                });
            });

        return $expired;
    }

    /**
     * Disabled users can't import: drop whatever they still have waiting.
     */
    public function cancelQueued(User $user): void
    {
        $user->mediaImports()
            ->where('status', MediaImport::STATUS_QUEUED)
            ->get()
            ->each(function (MediaImport $import) {
                try {
                    $this->discard($import);
                } catch (ImportRejectedException) {
                    // A worker claimed it meanwhile; it settles on its own.
                }
            });
    }

    private function purge(MediaImport $import): void
    {
        // A ready import's thumbnail is its track's cover; the track owns it.
        if ($import->status !== MediaImport::STATUS_READY) {
            $this->media->delete($import->thumbnail_path);
        }

        File::deleteDirectory($import->workDir());
        MediaImport::query()
            ->whereKey($import->id)
            ->whereNotIn('status', MediaImport::RUNNING_STATUSES)
            ->delete();
    }

    private function lockImport(MediaImport $import): ?MediaImport
    {
        return MediaImport::query()->whereKey($import->id)->lockForUpdate()->first();
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
