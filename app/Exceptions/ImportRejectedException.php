<?php

namespace App\Exceptions;

use App\Models\MediaImport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A YouTube import refused before queuing (bad URL, duplicate, limits).
 * Renders as `{message, code, ...extra}` so the SPA can map `code` to its copy.
 */
class ImportRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|int>  $headers
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidUrl(): self
    {
        return new self(MediaImport::REASON_INVALID_URL, 'That doesn\'t look like a YouTube video link.', 422);
    }

    public static function playlist(): self
    {
        return new self(MediaImport::REASON_PLAYLIST, 'Playlists aren\'t supported. Paste a link to a single video.', 422);
    }

    public static function duplicate(string $type, int $id): self
    {
        $message = $type === 'track' ? 'Already in your library.' : 'Already importing this video.';

        return new self('duplicate', $message, 409, ['existing' => ['type' => $type, 'id' => $id]]);
    }

    public static function quotaExceeded(): self
    {
        return new self(MediaImport::REASON_QUOTA, 'Storage full. Delete tracks or ask for more space.', 422);
    }

    public static function tooManyActive(int $maxActive): self
    {
        return new self('too_many_imports', "{$maxActive} imports are already running. Wait for one to finish.", 429);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        $minutes = (int) ceil($retryAfterSeconds / 60);

        return new self(
            'rate_limited',
            "Import limit reached. Try again in {$minutes} min.",
            429,
            ['retry_after' => $retryAfterSeconds],
            ['Retry-After' => $retryAfterSeconds],
        );
    }

    public static function notAllowed(string $message): self
    {
        return new self('invalid_state', $message, 422);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'code' => $this->reason, ...$this->extra],
            $this->status,
            $this->headers,
        );
    }
}
