<?php

namespace App\Http\Resources;

use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Track */
class TrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist_name' => $this->artist_name,
            'album_name' => $this->album_name,
            'duration_ms' => $this->duration_ms,
            'mime' => $this->mime,
            'size' => $this->size,
            'visibility' => $this->visibility,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'import_mode' => $this->import_mode,
            'license_url' => $this->license_url,
            'cover_url' => $this->coverUrl(),
            'stream_url' => $this->streamUrl(),
            'liked' => (bool) ($this->liked ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function coverUrl(): ?string
    {
        if ($this->external_cover_url) {
            return $this->external_cover_url;
        }

        if (! $this->cover_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.tracks.cover',
            now()->addHours(6),
            ['track' => $this->id],
        );
    }

    private function streamUrl(): ?string
    {
        if ($this->import_mode === 'linked' && $this->external_stream_url) {
            return $this->external_stream_url;
        }

        if (! $this->storage_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'api.tracks.stream',
            now()->addHours(6),
            ['track' => $this->id],
        );
    }
}
