<?php

namespace App\Http\Resources;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Playlist */
class PlaylistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'track_count' => $this->tracks_count
                ?? ($this->relationLoaded('tracks') ? $this->tracks->count() : 0),
            'cover_url' => $this->coverPreviewUrl(),
            'tracks' => $this->when(
                $this->relationLoaded('tracks') && $request->route('playlist') !== null,
                fn () => TrackResource::collection($this->tracks),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function coverPreviewUrl(): ?string
    {
        if (! $this->relationLoaded('tracks')) {
            return null;
        }

        $first = $this->tracks->first();

        if (! $first?->cover_path) {
            return null;
        }

        return TrackResource::absoluteMediaUrl(URL::temporarySignedRoute(
            'api.tracks.cover',
            now()->addHours(6),
            ['track' => $first->id],
            absolute: false,
        ));
    }
}
