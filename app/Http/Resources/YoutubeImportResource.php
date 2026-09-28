<?php

namespace App\Http\Resources;

use App\Models\YoutubeImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin YoutubeImport */
class YoutubeImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status === YoutubeImport::STATUS_WAITING_METADATA
            ? YoutubeImport::STATUS_DOWNLOADING
            : $this->status;

        return [
            'id' => $this->id,
            'url' => $this->url,
            'video_id' => $this->video_id,
            'title' => $this->title,
            'status' => $status,
            'status_message' => $this->status_message,
            'error_message' => $this->error_message,
            'reason_code' => $this->reasonCode(),
            'progress' => $this->progress,
            'track_id' => $this->track_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function reasonCode(): ?string
    {
        if ($this->status !== YoutubeImport::STATUS_FAILED) {
            return null;
        }

        $message = strtolower((string) $this->error_message);

        if (str_contains($message, 'private') || str_contains($message, 'unavailable') || str_contains($message, 'blocked')) {
            return 'blocked_by_youtube';
        }

        return 'download_failed';
    }
}
