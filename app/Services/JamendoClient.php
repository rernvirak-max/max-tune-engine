<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JamendoClient
{
    /**
     * Search public Jamendo catalog tracks.
     *
     * @return list<array<string, mixed>>
     */
    public function searchTracks(string $query, int $limit = 20, int $offset = 0): array
    {
        $clientId = $this->clientId();

        try {
            $response = Http::connectTimeout(3)
                ->timeout(12)
                ->retry([200, 500], 0, function (\Throwable $exception) {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response?->serverError() || $exception->response?->status() === 429));
                })
                ->get('https://api.jamendo.com/v3.0/tracks/', [
                    'client_id' => $clientId,
                    'format' => 'json',
                    'limit' => min(max($limit, 1), 50),
                    'offset' => max($offset, 0),
                    'search' => $query,
                    'include' => 'musicinfo+licenses',
                    'audioformat' => 'mp32',
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('Jamendo catalog request failed.', previous: $e);
        }

        $payload = $response->json();
        $headers = $payload['headers'] ?? [];

        if (($headers['status'] ?? null) === 'failed') {
            $message = (string) ($headers['error_message'] ?? 'Jamendo catalog unavailable.');
            throw new RuntimeException($message);
        }

        $results = $payload['results'] ?? [];

        return array_values(array_map(
            fn (array $row) => $this->mapTrack($row),
            is_array($results) ? $results : [],
        ));
    }

    /**
     * Fetch one track by Jamendo id.
     *
     * @return array<string, mixed>|null
     */
    public function getTrack(string $externalId): ?array
    {
        $clientId = $this->clientId();

        $response = Http::connectTimeout(3)
            ->timeout(12)
            ->get('https://api.jamendo.com/v3.0/tracks/', [
                'client_id' => $clientId,
                'format' => 'json',
                'id' => $externalId,
                'include' => 'musicinfo+licenses',
                'audioformat' => 'mp32',
            ])
            ->throw();

        $payload = $response->json();
        $headers = $payload['headers'] ?? [];

        if (($headers['status'] ?? null) === 'failed') {
            throw new RuntimeException((string) ($headers['error_message'] ?? 'Jamendo track lookup failed.'));
        }

        $row = $payload['results'][0] ?? null;

        return is_array($row) ? $this->mapTrack($row) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapTrack(array $row): array
    {
        $durationSec = (int) ($row['duration'] ?? 0);
        $licenses = $row['licenses'] ?? null;
        $licenseUrl = null;

        if (is_array($licenses)) {
            $licenseUrl = $licenses['url'] ?? ($licenses[0]['url'] ?? null);
        }

        return [
            'external_id' => (string) ($row['id'] ?? ''),
            'title' => (string) ($row['name'] ?? 'Untitled'),
            'artist_name' => (string) ($row['artist_name'] ?? ''),
            'album_name' => (string) ($row['album_name'] ?? ''),
            'duration_ms' => $durationSec > 0 ? $durationSec * 1000 : null,
            'stream_url' => $row['audio'] ?? null,
            'cover_url' => $row['album_image'] ?? ($row['image'] ?? null),
            'license_url' => is_string($licenseUrl) ? $licenseUrl : null,
            'source' => 'jamendo',
        ];
    }

    private function clientId(): string
    {
        $clientId = trim((string) config('max-tune.jamendo.client_id', ''));

        if ($clientId === '') {
            throw new RuntimeException(
                'Jamendo is not configured. Set JAMENDO_CLIENT_ID in .env (create an app at https://devportal.jamendo.com).'
            );
        }

        return $clientId;
    }
}
