<?php

namespace App\Http\Resources;

use App\Models\MediaImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MediaImport */
class MediaImportResource extends JsonResource
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
            'channel' => $this->channel,
            'thumbnail_url' => $this->thumbnail_path
                ? TrackResource::signedMediaUrl('api.imports.thumbnail', ['mediaImport' => $this->id])
                : null,
            'status' => $this->status,
            'reason_code' => $this->reason_code,
            'track_id' => $this->track_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
