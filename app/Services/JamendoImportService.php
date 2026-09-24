<?php

namespace App\Services;

use App\Models\Track;
use App\Models\User;
use RuntimeException;

class JamendoImportService
{
    public function __construct(private JamendoClient $jamendo) {}

    public function importLinked(User $user, string $externalId): Track
    {
        $existing = Track::query()
            ->where('user_id', $user->id)
            ->where('source', 'jamendo')
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $remote = $this->jamendo->getTrack($externalId);

        if (! $remote || empty($remote['stream_url'])) {
            throw new RuntimeException('Jamendo track not found or has no stream URL.');
        }

        return Track::query()->create([
            'user_id' => $user->id,
            'imported_by' => $user->id,
            'title' => $remote['title'],
            'artist_name' => $remote['artist_name'] ?: null,
            'album_name' => $remote['album_name'] ?: null,
            'duration_ms' => $remote['duration_ms'],
            'mime' => 'audio/mpeg',
            'size' => 0,
            'storage_path' => null,
            'cover_path' => null,
            'visibility' => 'private',
            'source' => 'jamendo',
            'external_id' => $remote['external_id'],
            'license_url' => $remote['license_url'],
            'import_mode' => 'linked',
            'external_stream_url' => $remote['stream_url'],
            'external_cover_url' => $remote['cover_url'],
        ]);
    }
}
