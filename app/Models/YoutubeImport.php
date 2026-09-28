<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeImport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_WAITING_METADATA = 'waiting_for_metadata';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'track_id',
        'url',
        'video_id',
        'title',
        'status',
        'status_message',
        'error_message',
        'progress',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_WAITING_METADATA,
            self::STATUS_DOWNLOADING,
            self::STATUS_PROCESSING,
        ], true);
    }

    public function isCancellable(): bool
    {
        return $this->isActive();
    }
}
