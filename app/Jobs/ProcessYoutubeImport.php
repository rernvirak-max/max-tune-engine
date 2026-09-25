<?php

namespace App\Jobs;

use App\Models\MediaImport;
use App\Services\YoutubeImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * One attempt at a YouTube import. Runs once (--tries=1); blocked-by-YouTube
 * retries are re-dispatched with backoff by the processor itself.
 */
class ProcessYoutubeImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public MediaImport $import)
    {
        $this->onQueue(config('max-tune.youtube.queue'));
    }

    public function handle(YoutubeImportProcessor $processor): void
    {
        // Dismissed, retried or already picked up since this job was queued.
        if ($this->import->status !== MediaImport::STATUS_QUEUED) {
            return;
        }

        $processor->process($this->import);
    }

    /**
     * The worker killed or lost the attempt (timeout, restart), so the
     * processor never reached its own failure handling.
     */
    public function failed(?Throwable $exception): void
    {
        $import = $this->import->refresh();
        $reason = $exception instanceof TimeoutExceededException
            ? MediaImport::REASON_TIMEOUT
            : MediaImport::REASON_INTERRUPTED;

        File::deleteDirectory($import->workDir());
        app(YoutubeImportProcessor::class)->fail($import, $reason, $exception?->getMessage());
    }
}
