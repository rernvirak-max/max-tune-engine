<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin YouTube Data API v3 client for catalog search (metadata only).
 * Download/import still goes through ProcessYoutubeImport + yt-dlp.
 */
class YoutubeDataClient
{
    private const SEARCH_URL = 'https://www.googleapis.com/youtube/v3/search';

    private const VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';

    /**
     * @return list<array<string, mixed>>
     */
    public function searchVideos(string $query, int $limit = 20): array
    {
        $apiKey = $this->apiKey();
        $limit = min(max($limit, 1), 50);

        try {
            $search = Http::connectTimeout(3)
                ->timeout(12)
                ->retry([200, 500], 0, function (\Throwable $exception) {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response?->serverError() || $exception->response?->status() === 429));
                })
                ->get(self::SEARCH_URL, [
                    'key' => $apiKey,
                    'part' => 'snippet',
                    'type' => 'video',
                    'q' => $query,
                    'maxResults' => $limit,
                    'safeSearch' => 'moderate',
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new RuntimeException($this->apiErrorMessage($e, 'YouTube search failed.'), previous: $e);
        }

        $items = is_array($search['items'] ?? null) ? $search['items'] : [];
        $videoIds = [];

        foreach ($items as $item) {
            $id = $item['id']['videoId'] ?? null;
            if (is_string($id) && $id !== '') {
                $videoIds[] = $id;
            }
        }

        $durations = $this->durationsById($apiKey, $videoIds);

        return array_values(array_filter(array_map(
            fn (array $item) => $this->mapSearchItem($item, $durations),
            $items,
        )));
    }

    /**
     * @param  list<string>  $videoIds
     * @return array<string, int|null> videoId => duration_ms
     */
    private function durationsById(string $apiKey, array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }

        try {
            $payload = Http::connectTimeout(3)
                ->timeout(12)
                ->get(self::VIDEOS_URL, [
                    'key' => $apiKey,
                    'part' => 'contentDetails',
                    'id' => implode(',', $videoIds),
                ])
                ->throw()
                ->json();
        } catch (RequestException) {
            return [];
        }

        $map = [];

        foreach ($payload['items'] ?? [] as $item) {
            $id = $item['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $map[$id] = $this->iso8601DurationToMs((string) ($item['contentDetails']['duration'] ?? ''));
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int|null>  $durations
     * @return array<string, mixed>|null
     */
    private function mapSearchItem(array $item, array $durations): ?array
    {
        $videoId = $item['id']['videoId'] ?? null;

        if (! is_string($videoId) || $videoId === '') {
            return null;
        }

        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $thumbs = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
        $cover = $thumbs['medium']['url']
            ?? $thumbs['high']['url']
            ?? $thumbs['default']['url']
            ?? null;

        return [
            'external_id' => $videoId,
            'title' => (string) ($snippet['title'] ?? 'Untitled'),
            'artist_name' => (string) ($snippet['channelTitle'] ?? ''),
            'album_name' => '',
            'duration_ms' => $durations[$videoId] ?? null,
            'cover_url' => is_string($cover) ? $cover : null,
            'watch_url' => app(YoutubeUrl::class)->watchUrl($videoId),
            'source' => 'youtube',
        ];
    }

    private function iso8601DurationToMs(string $duration): ?int
    {
        if ($duration === '' || ! preg_match(
            '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/',
            $duration,
            $m,
        )) {
            return null;
        }

        $seconds = ((int) ($m[1] ?? 0)) * 3600
            + ((int) ($m[2] ?? 0)) * 60
            + ((int) ($m[3] ?? 0));

        return $seconds > 0 ? $seconds * 1000 : null;
    }

    private function apiKey(): string
    {
        $key = trim((string) config('max-tune.youtube.data_api_key', ''));

        if ($key === '') {
            throw new RuntimeException(
                'YouTube search is not configured. Set YOUTUBE_DATA_API_KEY in .env (Google Cloud → YouTube Data API v3).'
            );
        }

        return $key;
    }

    private function apiErrorMessage(RequestException $e, string $fallback): string
    {
        $body = $e->response?->json();
        $message = $body['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
