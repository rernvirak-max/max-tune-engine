<?php

namespace App\Services;

use App\Exceptions\ImportRejectedException;

/**
 * Parses user-pasted YouTube links down to a single video ID. yt-dlp only
 * ever sees watchUrl($id), never the raw input.
 */
class YoutubeUrl
{
    private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const WATCH_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'];

    private const SHORT_HOSTS = ['youtu.be', 'www.youtu.be'];

    /**
     * @throws ImportRejectedException invalid_url | playlist_not_supported
     */
    public function videoId(string $input): string
    {
        $input = trim($input);

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));
        parse_str($parts['query'] ?? '', $query);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ImportRejectedException::invalidUrl();
        }

        $candidate = match (true) {
            in_array($host, self::SHORT_HOSTS, true) => $segments[0] ?? null,
            in_array($host, self::WATCH_HOSTS, true) => $this->watchHostVideoId($segments, $query),
            default => null,
        };

        if (is_string($candidate) && preg_match(self::VIDEO_ID_PATTERN, $candidate)) {
            return $candidate;
        }

        throw ImportRejectedException::invalidUrl();
    }

    public function watchUrl(string $videoId): string
    {
        return 'https://www.youtube.com/watch?v='.$videoId;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $query
     */
    private function watchHostVideoId(array $segments, array $query): ?string
    {
        $first = $segments[0] ?? '';

        if ($first === 'shorts') {
            return $segments[1] ?? null;
        }

        if ($first === 'watch' && isset($query['v'])) {
            // `list=` on a watch URL is ignored: only the `v=` video is imported.
            return is_string($query['v']) ? $query['v'] : null;
        }

        if (in_array($first, ['watch', 'playlist'], true) && isset($query['list'])) {
            throw ImportRejectedException::playlist();
        }

        return null;
    }
}
