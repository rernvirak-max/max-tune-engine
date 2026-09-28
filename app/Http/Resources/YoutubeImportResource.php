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
        return [
            'id' => $this->id,
            'url' => $this->url,
            'video_id' => $this->video_id,
            'title' => $this->title,
            'status' => $this->status,
            'status_message' => $this->status_message,
            'error_message' => $this->error_message,
            'progress' => $this->progress,
            'track_id' => $this->track_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
