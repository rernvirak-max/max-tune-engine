<?php

namespace App\Services;

use App\Exceptions\MediaImportFailedException;
use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one import attempt: metadata first (fail early), then audio, then a
 * normal private track under the owner's quota. Leaves no scratch or media
 * files behind on failure.
 */
class YoutubeImportProcessor
{
    /** YouTube's only age gate */
    private const AGE_RESTRICTED_MIN_AGE = 18;

    private const LIVE_STATUSES = ['is_live', 'is_upcoming', 'post_live'];

    private const PRIVATE_AVAILABILITY = ['private', 'premium_only', 'subscriber_only', 'needs_auth'];

    private const TOPIC_CHANNEL_SUFFIX = ' - Topic';

    private const AUDIO_MIME = 'audio/mp4';

    public function __construct(
        private YtDlp $ytDlp,
        private YoutubeUrl $urls,
        private MediaStorage $media,
        private TrackUploadService $tracks,
    ) {}

    public function process(MediaImport $import): void
    {
        if (! $this->claim($import)) {
            return;
        }

        $deadline = microtime(true) + (int) config('max-tune.youtube.job_timeout_seconds');
        $workDir = $import->workDir();

        // Created only after the claim, so the sweep never sees a scratch dir without a running row.
        File::deleteDirectory($workDir);
        File::ensureDirectoryExists($workDir);

        try {
            $metadata = $this->ytDlp->fetchMetadata($this->urls->watchUrl($import->video_id), $workDir, $deadline - microtime(true));

            if (! $this->rememberMetadata($import, $metadata['info'], $metadata['thumbnail_path'])) {
                return;
            }

            $this->assertImportable($metadata['info']);

            $audioPath = $this->ytDlp->downloadAudio(
                $metadata['info_path'],
                $workDir,
                $this->maxFileBytes(),
                $deadline - microtime(true),
            );

            if (! $this->advance($import, MediaImport::STATUS_DOWNLOADING, MediaImport::STATUS_PROCESSING)) {
                return;
            }

            $this->storeTrack($import, $metadata['info'], $audioPath);
        } catch (MediaImportFailedException $e) {
            $this->fail($import, $e->reason, $e->detail);
        } catch (Throwable $e) {
            report($e);
            $this->fail($import, MediaImport::REASON_UNKNOWN, $e->getMessage());
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Settle an import a worker holds (or, for the sweep, one never picked up).
     * Blocked by YouTube: back to queued (the user sees "Queued") and retry
     * with backoff until the configured delays run out, then fail for real.
     * Conditional on the row still being in one of $from, so a ready, dismissed
     * or re-queued import is never overwritten.
     *
     * @param  list<string>  $from
     */
    public function fail(
        MediaImport $import,
        string $reason,
        ?string $detail = null,
        array $from = MediaImport::RUNNING_STATUSES,
    ): void {
        $delays = (array) config('max-tune.youtube.blocked_retry_delays_seconds');
        $retryDelay = $delays[$import->attempts - 1] ?? null;
        $retrying = $reason === MediaImport::REASON_BLOCKED && $retryDelay !== null;

        $settled = MediaImport::query()
            ->whereKey($import->id)
            ->whereIn('status', $from)
            ->update([
                'status' => $retrying ? MediaImport::STATUS_QUEUED : MediaImport::STATUS_FAILED,
                'reason_code' => $retrying ? null : $reason,
                'error_detail' => $detail,
                'updated_at' => now(),
            ]);

        if ($settled === 0) {
            return;
        }

        $import->refresh();

        if ($retrying) {
            ProcessYoutubeImport::dispatch($import)->delay((int) $retryDelay);
        }
    }

    /**
     * Atomically move a queued import to downloading. False when another
     * worker already holds it or it was dismissed/retried since it was queued.
     */
    private function claim(MediaImport $import): bool
    {
        return $this->advance($import, MediaImport::STATUS_QUEUED, MediaImport::STATUS_DOWNLOADING, [
            'attempts' => DB::raw('attempts + 1'),
        ]);
    }

    /**
     * Write only while the row is still in $from (a transition, or with
     * $from === $to an attribute update). False, with nothing written, when
     * the row is gone or has moved on, e.g. swept or dismissed meanwhile.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function advance(MediaImport $import, string $from, string $to, array $attributes = []): bool
    {
        $moved = MediaImport::query()
            ->whereKey($import->id)
            ->where('status', $from)
            ->update([...$attributes, 'status' => $to, 'updated_at' => now()]);

        if ($moved === 0) {
            return false;
        }

        $import->refresh();

        return true;
    }

    /**
     * False when the import was settled or dismissed meanwhile (its new cover is removed again).
     *
     * @param  array<string, mixed>  $info
     */
    private function rememberMetadata(MediaImport $import, array $info, ?string $thumbnailPath): bool
    {
        $attributes = [
            'title' => $this->stringOrNull($info['title'] ?? null),
            'channel' => $this->artistName($info),
        ];

        // Keep the first thumbnail across automatic retries; it becomes the cover.
        if ($thumbnailPath !== null && $import->thumbnail_path === null) {
            $attributes['thumbnail_path'] = $this->media->storeCover($import->user_id, (string) file_get_contents($thumbnailPath));
        }

        if ($this->advance($import, MediaImport::STATUS_DOWNLOADING, MediaImport::STATUS_DOWNLOADING, $attributes)) {
            return true;
        }

        $this->media->delete($attributes['thumbnail_path'] ?? null);

        return false;
    }

    /**
     * @param  array<string, mixed>  $info
     *
     * @throws MediaImportFailedException
     */
    private function assertImportable(array $info): void
    {
        $reason = match (true) {
            ($info['is_live'] ?? false) === true,
            in_array($info['live_status'] ?? null, self::LIVE_STATUSES, true) => MediaImport::REASON_LIVE,
            in_array($info['availability'] ?? null, self::PRIVATE_AVAILABILITY, true) => MediaImport::REASON_PRIVATE,
            (int) ($info['age_limit'] ?? 0) >= self::AGE_RESTRICTED_MIN_AGE => MediaImport::REASON_AGE_RESTRICTED,
            (float) ($info['duration'] ?? 0) > (int) config('max-tune.youtube.max_duration_seconds') => MediaImport::REASON_TOO_LONG,
            default => null,
        };

        if ($reason !== null) {
            throw new MediaImportFailedException($reason);
        }
    }

    /**
     * Create the track and mark the import ready in one transaction, under a
     * lock on the import row. If the row was dismissed or settled meanwhile
     * (e.g. swept as interrupted), no track is created and the audio is removed.
     *
     * @param  array<string, mixed>  $info
     *
     * @throws MediaImportFailedException too_large | quota_exceeded
     */
    private function storeTrack(MediaImport $import, array $info, string $audioPath): void
    {
        $audioBytes = (int) filesize($audioPath);

        if ($audioBytes > $this->maxFileBytes()) {
            throw new MediaImportFailedException(MediaImport::REASON_TOO_LARGE, "Audio is {$audioBytes} bytes.");
        }

        $storagePath = $this->media->storeTrackAudioFile($import->user_id, $audioPath);

        try {
            $track = DB::transaction(function () use ($import, $info, $audioBytes, $storagePath) {
                $held = MediaImport::query()
                    ->whereKey($import->id)
                    ->where('status', MediaImport::STATUS_PROCESSING)
                    ->lockForUpdate()
                    ->exists();

                if (! $held) {
                    return null;
                }

                $track = $this->tracks->createWithinQuota($import->owner, [
                    'title' => $import->title ?? $import->video_id,
                    'artist_name' => $import->channel,
                    'duration_ms' => isset($info['duration']) ? (int) round((float) $info['duration'] * 1000) : null,
                    'mime' => self::AUDIO_MIME,
                    'size' => $audioBytes + $this->media->size($import->thumbnail_path),
                    'storage_path' => $storagePath,
                    'cover_path' => $import->thumbnail_path,
                    'source' => MediaImport::TRACK_SOURCE,
                    'source_id' => $import->video_id,
                ]);

                $import->update([
                    'status' => MediaImport::STATUS_READY,
                    'track_id' => $track->id,
                    'reason_code' => null,
                    'error_detail' => null,
                ]);

                return $track;
            });
        } catch (Throwable $e) {
            $this->media->delete($storagePath);

            throw $e instanceof ValidationException ? new MediaImportFailedException(MediaImport::REASON_QUOTA) : $e;
        }

        if ($track === null) {
            $this->media->delete($storagePath);
        }
    }

    /**
     * Channel/uploader with YouTube Music's auto-generated " - Topic" suffix removed.
     *
     * @param  array<string, mixed>  $info
     */
    private function artistName(array $info): ?string
    {
        $channel = $this->stringOrNull($info['channel'] ?? $info['uploader'] ?? null);

        if ($channel !== null && str_ends_with($channel, self::TOPIC_CHANNEL_SUFFIX)) {
            return substr($channel, 0, -strlen(self::TOPIC_CHANNEL_SUFFIX));
        }

        return $channel;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function maxFileBytes(): int
    {
        return (int) config('max-tune.max_upload_kb') * 1024;
    }
}
