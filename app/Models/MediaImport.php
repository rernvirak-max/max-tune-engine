<?php

namespace App\Models;

use Database\Factories\MediaImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'url',
    'video_id',
    'status',
    'reason_code',
    'error_detail',
    'attempts',
    'title',
    'channel',
    'thumbnail_path',
    'track_id',
])]
class MediaImport extends Model
{
    /** @use HasFactory<MediaImportFactory> */
    use HasFactory;

    /** tracks.source for imported tracks (tracks.source_id holds the video ID) */
    public const TRACK_SOURCE = 'youtube';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_DOWNLOADING, self::STATUS_PROCESSING];

    /** A worker holds the import; it cannot be dismissed or deleted until it settles (no cancel in v1). */
    public const RUNNING_STATUSES = [self::STATUS_DOWNLOADING, self::STATUS_PROCESSING];

    public const REASON_INVALID_URL = 'invalid_url';

    public const REASON_PLAYLIST = 'playlist_not_supported';

    public const REASON_UNAVAILABLE = 'unavailable';

    public const REASON_PRIVATE = 'private';

    public const REASON_AGE_RESTRICTED = 'age_restricted';

    public const REASON_LIVE = 'live';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_QUOTA = 'quota_exceeded';

    public const REASON_BLOCKED = 'blocked_by_youtube';

    public const REASON_TIMEOUT = 'timeout';

    public const REASON_INTERRUPTED = 'interrupted';

    public const REASON_UNKNOWN = 'unknown';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @param  Builder<MediaImport>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING_STATUSES, true);
    }

    /**
     * Per-import scratch directory for yt-dlp output (never under the media disk).
     */
    public function workDir(): string
    {
        return config('max-tune.youtube.work_dir').DIRECTORY_SEPARATOR.$this->id;
    }
}
