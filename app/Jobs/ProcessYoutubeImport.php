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
 * One attempt at a YouTube import. Runs once; blocked-by-YouTube retries are
 * re-dispatched with backoff by the processor itself. Connection, queue and
 * timeout come from config.
 *
 * Correctness requires the worker to pull from the `database-imports`
 * connection: Laravel applies the retry_after of the connection the worker
 * runs on, and only that one outlives this job's $timeout. Run exactly:
 *   php artisan queue:work database-imports --queue=imports --timeout=660 --tries=1 --sleep=3
 * (The default `database` connection's retry_after is also kept above 660 as a
 * safety net, see config/queue.php.)
 */
class ProcessYoutubeImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    /** Worker hard limit: the job's own budget plus a margin for cleanup */
    public int $timeout;

    public function __construct(public MediaImport $import)
    {
        $this->timeout = (int) config('max-tune.youtube.job_timeout_seconds')
            + (int) config('max-tune.youtube.worker_timeout_margin_seconds');

        $this->onConnection(config('max-tune.youtube.queue_connection'));
        $this->onQueue(config('max-tune.youtube.queue'));
    }

    /**
     * The processor claims the row atomically, so a dismissed, retried or
     * already running import (e.g. a duplicate delivery) is a no-op.
     */
    public function handle(YoutubeImportProcessor $processor): void
    {
        $processor->process($this->import);
    }

    /**
     * The worker killed or lost the attempt (timeout, restart), so the
     * processor never reached its own failure handling.
     */
    public function failed(?Throwable $exception): void
    {
        $import = MediaImport::find($this->import->getKey());

        // Gone, never started, or already settled (never overwrite a ready import).
        if (! $import?->isRunning()) {
            return;
        }

        $reason = $exception instanceof TimeoutExceededException
            ? MediaImport::REASON_TIMEOUT
            : MediaImport::REASON_INTERRUPTED;

        File::deleteDirectory($import->workDir());
        app(YoutubeImportProcessor::class)->fail($import, $reason, $exception?->getMessage());
    }
}
