<?php

namespace App\Services;

use getID3;

class AudioMetadataExtractor
{
    /**
     * @return array{
     *     title: ?string,
     *     artist_name: ?string,
     *     album_name: ?string,
     *     duration_ms: ?int,
     *     cover_binary: ?string,
     *     cover_mime: ?string
     * }
     */
    public function extract(string $absolutePath): array
    {
        $analyzer = new getID3;
        $info = $analyzer->analyze($absolutePath);

        $tags = $this->flattenTags($info);
        $durationMs = isset($info['playtime_seconds'])
            ? (int) round(((float) $info['playtime_seconds']) * 1000)
            : null;

        [$coverBinary, $coverMime] = $this->extractCover($info);

        return [
            'title' => $this->stringOrNull($tags['title'] ?? null),
            'artist_name' => $this->stringOrNull($tags['artist'] ?? $tags['band'] ?? null),
            'album_name' => $this->stringOrNull($tags['album'] ?? null),
            'duration_ms' => $durationMs,
            'cover_binary' => $coverBinary,
            'cover_mime' => $coverMime,
        ];
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function flattenTags(array $info): array
    {
        $comments = $info['comments'] ?? [];
        if (! is_array($comments)) {
            return [];
        }

        $flat = [];
        foreach ($comments as $key => $values) {
            if (is_array($values) && isset($values[0])) {
                $flat[strtolower((string) $key)] = $values[0];
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{0: ?string, 1: ?string}
     */
    private function extractCover(array $info): array
    {
        $pictures = $info['comments']['picture'] ?? null;
        if (! is_array($pictures) || $pictures === []) {
            return [null, null];
        }

        $picture = $pictures[0] ?? null;
        if (! is_array($picture) || empty($picture['data'])) {
            return [null, null];
        }

        $mime = is_string($picture['image_mime'] ?? null)
            ? $picture['image_mime']
            : 'image/jpeg';

        return [$picture['data'], $mime];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
