<?php

declare(strict_types=1);

namespace App\Import;

use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Jobs\ReadImportFile;
use App\Models\Import;
use Illuminate\Bus\Batch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

final class ImportService
{
    /** How long Redis keeps counters of a finished import */
    private const PROGRESS_TTL = 86400;

    public function __construct(private readonly ImportProgress $progress) {}

    /**
     * Stores the uploaded file and starts the import batch.
     */
    public function start(UploadedFile $file): Import
    {
        $import = Import::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => '',
        ]);

        $import->update(['file_path' => $file->storeAs("imports/{$import->id}", 'source.xlsx')]);

        $importId = $import->id;

        $batch = Bus::batch([new ReadImportFile($importId)])
            ->name("import {$importId}")
            // One broken chunk must not stop the rest of the file
            ->allowFailures()
            // Runs once, after every job (including chunks added later) has finished or failed
            ->finally(fn (Batch $batch) => app(self::class)->finish($importId, $batch->failedJobs))
            ->dispatch();

        $import->update(['batch_id' => $batch->id]);

        return $import;
    }

    /**
     * Writes result.txt and stores the final counters.
     */
    public function finish(string $importId, int $failedJobs = 0): void
    {
        $import = Import::findOrFail($importId);

        $reportPath = "imports/{$import->id}/result.txt";
        Storage::makeDirectory(dirname($reportPath));
        $this->writeReport($import->id, Storage::path($reportPath));

        $error = $import->error;
        if ($failedJobs > 0) {
            $error = trim($error."\n{$failedJobs} job(s) failed, see the logs.");
        }

        $import->update([
            'status' => $failedJobs > 0 ? ImportStatus::Failed : ImportStatus::Completed,
            'processed_rows' => $this->progress->processed($import->id),
            'failed_rows' => $this->progress->failed($import->id),
            'report_path' => $reportPath,
            'error' => $error,
            'finished_at' => now(),
        ]);

        $this->progress->expire($import->id, self::PROGRESS_TTL);

        rescue(fn () => ImportFinished::dispatch($import->id, $import->status->value));
    }

    /**
     * Current processed/failed counters: live from Redis while running, from the database after.
     *
     * @return array{processed: int, failed: int}
     */
    public function counters(Import $import): array
    {
        if ($import->status->isFinished()) {
            return ['processed' => $import->processed_rows, 'failed' => $import->failed_rows];
        }

        return [
            'processed' => $this->progress->processed($import->id),
            'failed' => $this->progress->failed($import->id),
        ];
    }

    /**
     * Deletes an import with its files and Redis keys. Imported rows stay unless $withRows.
     */
    public function delete(Import $import, bool $withRows = false): void
    {
        if ($withRows) {
            $import->rows()->delete();
        }

        Storage::deleteDirectory("imports/{$import->id}");
        $this->progress->forget($import->id);
        $import->delete();
    }

    private function writeReport(string $importId, string $path): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot write report to {$path}");
        }

        try {
            foreach ($this->progress->errorLines($importId) as $line) {
                fwrite($handle, $line."\n");
            }
        } finally {
            fclose($handle);
        }
    }
}
