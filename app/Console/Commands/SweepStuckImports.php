<?php

namespace App\Console\Commands;

use App\Models\MediaImport;
use App\Services\YoutubeImportProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('imports:sweep')]
#[Description('Fail YouTube imports stuck past the job timeout and delete orphaned scratch files')]
class SweepStuckImports extends Command
{
    public function handle(YoutubeImportProcessor $processor): int
    {
        $cutoff = now()->subSeconds(
            (int) config('max-tune.youtube.job_timeout_seconds') + (int) config('max-tune.youtube.stuck_grace_seconds'),
        );

        $stuck = MediaImport::query()
            ->whereIn('status', MediaImport::RUNNING_STATUSES)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $stuck->each(fn (MediaImport $import) => $processor->fail(
            $import,
            MediaImport::REASON_INTERRUPTED,
            'Marked interrupted by imports:sweep (no progress since '.$import->updated_at?->toIso8601String().').',
        ));

        $orphanDirs = $this->deleteOrphanWorkDirs();

        $this->info("Interrupted {$stuck->count()} stuck import(s); removed {$orphanDirs} orphaned scratch dir(s).");

        return self::SUCCESS;
    }

    /**
     * Scratch dirs whose import is gone or no longer running (e.g. worker killed mid-download).
     */
    private function deleteOrphanWorkDirs(): int
    {
        $root = (string) config('max-tune.youtube.work_dir');

        if (! File::isDirectory($root)) {
            return 0;
        }

        $runningIds = MediaImport::query()
            ->whereIn('status', MediaImport::RUNNING_STATUSES)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $orphans = collect(File::directories($root))
            ->reject(fn (string $dir) => in_array(basename($dir), $runningIds, true));

        $orphans->each(fn (string $dir) => File::deleteDirectory($dir));

        return $orphans->count();
    }
}
