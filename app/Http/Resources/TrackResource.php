<?php

namespace App\Http\Resources;

use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Track */
class TrackResource extends JsonResource
{
    private const SIGNED_URL_TTL_HOURS = 6;

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

        return self::signedMediaUrl('api.tracks.cover', ['track' => $this->id]);
    }

    private function streamUrl(): ?string
    {
        if ($this->import_mode === 'linked' && $this->external_stream_url) {
            return $this->external_stream_url;
        }

        if (! $this->storage_path) {
            return null;
        }

        return self::signedMediaUrl('api.tracks.stream', ['track' => $this->id]);
    }

    /**
     * Temporary signed media URL (cover, stream, import thumbnail) for <img>/<audio>.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function signedMediaUrl(string $routeName, array $parameters): string
    {
        return self::absoluteMediaUrl(URL::temporarySignedRoute(
            $routeName,
            now()->addHours(self::SIGNED_URL_TTL_HOURS),
            $parameters,
            absolute: false,
        ));
    }

    /**
     * Signatures cover path + query only, so they survive TLS termination at
     * Cloudflare/Traefik; the public origin comes from APP_URL (https in prod).
     */
    public static function absoluteMediaUrl(string $relative): string
    {
        return rtrim((string) config('app.url'), '/').$relative;
    }
}
