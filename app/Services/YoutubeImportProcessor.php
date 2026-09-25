<?php

namespace App\Services;

use App\Exceptions\MediaImportFailedException;
use App\Jobs\ProcessYoutubeImport;
use App\Models\MediaImport;
use App\Models\Track;
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
        $deadline = microtime(true) + (int) config('max-tune.youtube.job_timeout_seconds');
        $workDir = $import->workDir();

        File::deleteDirectory($workDir);
        File::ensureDirectoryExists($workDir);

        $import->update([
            'status' => MediaImport::STATUS_DOWNLOADING,
            'attempts' => $import->attempts + 1,
        ]);

        try {
            $metadata = $this->ytDlp->fetchMetadata($this->urls->watchUrl($import->video_id), $workDir, $deadline - microtime(true));
            $this->rememberMetadata($import, $metadata['info'], $metadata['thumbnail_path']);
            $this->assertImportable($metadata['info']);

            $audioPath = $this->ytDlp->downloadAudio(
                $metadata['info_path'],
                $workDir,
                $this->maxFileBytes(),
                $deadline - microtime(true),
            );

            $import->update(['status' => MediaImport::STATUS_PROCESSING]);
            $track = $this->storeTrack($import, $metadata['info'], $audioPath);

            $import->update([
                'status' => MediaImport::STATUS_READY,
                'track_id' => $track->id,
                'reason_code' => null,
                'error_detail' => null,
            ]);
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
     * Blocked by YouTube: back to queued (the user sees "Queued") and retry
     * with backoff until the configured delays run out, then fail for real.
     */
    public function fail(MediaImport $import, string $reason, ?string $detail = null): void
    {
        $delays = (array) config('max-tune.youtube.blocked_retry_delays_seconds');
        $retryDelay = $delays[$import->attempts - 1] ?? null;

        if ($reason === MediaImport::REASON_BLOCKED && $retryDelay !== null) {
            $import->update([
                'status' => MediaImport::STATUS_QUEUED,
                'reason_code' => null,
                'error_detail' => $detail,
            ]);

            ProcessYoutubeImport::dispatch($import)->delay((int) $retryDelay);

            return;
        }

        $import->update([
            'status' => MediaImport::STATUS_FAILED,
            'reason_code' => $reason,
            'error_detail' => $detail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function rememberMetadata(MediaImport $import, array $info, ?string $thumbnailPath): void
    {
        $attributes = [
            'title' => $this->stringOrNull($info['title'] ?? null),
            'channel' => $this->artistName($info),
        ];

        // Keep the first thumbnail across automatic retries; it becomes the cover.
        if ($thumbnailPath !== null && $import->thumbnail_path === null) {
            $attributes['thumbnail_path'] = $this->media->storeCover($import->user_id, (string) file_get_contents($thumbnailPath));
        }

        $import->update($attributes);
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
     * @param  array<string, mixed>  $info
     *
     * @throws MediaImportFailedException too_large | quota_exceeded
     */
    private function storeTrack(MediaImport $import, array $info, string $audioPath): Track
    {
        $audioBytes = (int) filesize($audioPath);

        if ($audioBytes > $this->maxFileBytes()) {
            throw new MediaImportFailedException(MediaImport::REASON_TOO_LARGE, "Audio is {$audioBytes} bytes.");
        }

        $storagePath = $this->media->storeTrackAudioFile($import->user_id, $audioPath);

        try {
            return $this->tracks->createWithinQuota($import->owner, [
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
        } catch (Throwable $e) {
            $this->media->delete($storagePath);

            throw $e instanceof ValidationException ? new MediaImportFailedException(MediaImport::REASON_QUOTA) : $e;
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
