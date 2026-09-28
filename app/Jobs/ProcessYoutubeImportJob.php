<?php

namespace App\Jobs;

use App\Models\YoutubeImport;
use App\Services\TrackUploadService;
use App\Services\YoutubeDownloadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessYoutubeImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $importId) {}

    public function handle(YoutubeDownloadService $youtube, TrackUploadService $uploads): void
    {
        $import = YoutubeImport::query()->find($this->importId);

        if ($import === null || $import->status === YoutubeImport::STATUS_CANCELLED) {
            return;
        }

        $workDir = storage_path('app/tmp/youtube/'.$import->id);

        try {
            $import->update([
                'status' => YoutubeImport::STATUS_WAITING_METADATA,
                'status_message' => 'Waiting for metadata',
                'progress' => 5,
                'error_message' => null,
            ]);

            $meta = $youtube->fetchMetadata($import->url);

            if ($import->fresh()?->status === YoutubeImport::STATUS_CANCELLED) {
                return;
            }

            $import->update([
                'video_id' => $meta['id'] ?? $import->video_id,
                'title' => $meta['title'] ?? $import->title,
                'status' => YoutubeImport::STATUS_DOWNLOADING,
                'status_message' => 'Downloading audio',
                'progress' => 25,
            ]);

            $audioPath = $youtube->downloadAudio($import->url, $workDir);

            if ($import->fresh()?->status === YoutubeImport::STATUS_CANCELLED) {
                return;
            }

            $import->update([
                'status' => YoutubeImport::STATUS_PROCESSING,
                'status_message' => 'Adding to library',
                'progress' => 80,
            ]);

            $track = $uploads->importFromPath($import->user, $audioPath, [
                'title' => $meta['title'] ?? null,
                'artist_name' => $meta['uploader'] ?? null,
                'external_id' => $meta['id'] ?? $import->video_id,
                'source' => 'youtube',
            ]);

            $import->update([
                'track_id' => $track->id,
                'title' => $track->title,
                'status' => YoutubeImport::STATUS_DONE,
                'status_message' => 'Done',
                'progress' => 100,
                'error_message' => null,
            ]);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Import rejected.';
            $this->markFailed($import, $message);
        } catch (Throwable $e) {
            Log::warning('YouTube import failed', [
                'import_id' => $import->id,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($import, $e->getMessage());
            throw $e;
        } finally {
            if (is_dir($workDir)) {
                File::deleteDirectory($workDir);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = YoutubeImport::query()->find($this->importId);
        if ($import === null || $import->status === YoutubeImport::STATUS_DONE) {
            return;
        }

        if ($import->status !== YoutubeImport::STATUS_CANCELLED) {
            $this->markFailed($import, $exception?->getMessage() ?: 'YouTube import failed.');
        }
    }

    private function markFailed(YoutubeImport $import, string $message): void
    {
        $import->update([
            'status' => YoutubeImport::STATUS_FAILED,
            'status_message' => 'Failed',
            'error_message' => $message,
            'progress' => 0,
        ]);
    }
}
