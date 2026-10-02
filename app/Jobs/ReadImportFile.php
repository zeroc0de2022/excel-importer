<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Import\SpreadsheetReader;
use App\Models\Import;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * First job of an import batch: streams the file and adds one ProcessImportChunk job
 * per 1000 rows to the same batch. The batch can't finish while this job is still
 * running, so it never "completes" before all chunks have been added.
 */
class ReadImportFile implements ShouldQueue
{
    use Batchable, Queueable;

    // Re-reading would add every chunk again, so this job isn't retried
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $importId) {}

    public function handle(SpreadsheetReader $reader): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = Import::findOrFail($this->importId);
        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        $chunkSize = (int) config('import.chunk_size');
        $maxRows = (int) config('import.max_rows');

        $chunk = [];
        $chunkNumber = 0;
        $total = 0;

        foreach ($reader->rows(Storage::path($import->file_path)) as $line => $cells) {
            if ($maxRows > 0 && $total >= $maxRows) {
                $import->error = "The file has more than {$maxRows} rows; only the first {$maxRows} were imported.";
                break;
            }

            $chunk[$line] = $cells;
            $total++;

            if (count($chunk) === $chunkSize) {
                $this->batch()?->add([new ProcessImportChunk($import->id, $chunkNumber++, $chunk)]);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->batch()?->add([new ProcessImportChunk($import->id, $chunkNumber, $chunk)]);
        }

        $import->total_rows = $total;
        $import->save();
    }

    public function failed(?Throwable $exception): void
    {
        Import::whereKey($this->importId)->update([
            'error' => 'Could not read the file: '.($exception?->getMessage() ?? 'unknown error'),
        ]);
    }
}
