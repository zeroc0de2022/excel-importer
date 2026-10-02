<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\RowsCreated;
use App\Import\ImportProgress;
use App\Import\RowValidator;
use App\Import\ValidatedRow;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates up to 1000 rows and inserts the valid ones in a single statement.
 *
 * Safe to retry: the insert skips ids that already exist, rows remember which
 * import and row number created them, and progress is counted once per chunk.
 */
class ProcessImportChunk implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<int, array<int, mixed>>  $rows  spreadsheet row number => [id, name, date]
     */
    public function __construct(
        public readonly string $importId,
        public readonly int $chunk,
        public readonly array $rows,
    ) {}

    /**
     * Seconds to wait before each retry, growing exponentially.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 25, 125];
    }

    public function handle(RowValidator $validator, ImportProgress $progress): void
    {
        if ($this->batch()?->cancelled() || $progress->isChunkRecorded($this->importId, $this->chunk)) {
            return;
        }

        $errors = [];
        $valid = [];

        foreach ($this->rows as $line => $cells) {
            $row = $validator->validate($line, $cells);

            if (! $row->isValid()) {
                $errors[$line] = implode(', ', $row->errors);
            } elseif (isset($valid[$row->id])) {
                $errors[$line] = "duplicate id {$row->id} (first seen in row {$valid[$row->id]->line})";
            } else {
                $valid[$row->id] = $row;
            }
        }

        $duplicates = $this->insert($valid);
        $errors += $duplicates;
        ksort($errors);

        $report = [];
        foreach ($errors as $line => $message) {
            $report[$line] = "{$line} - {$message}";
        }

        $recorded = $progress->recordChunk($this->importId, $this->chunk, count($this->rows), count($errors), $report);

        if ($recorded) {
            // A WebSocket hiccup must not fail (and retry) a chunk whose data is already saved
            rescue(fn () => RowsCreated::dispatch(
                $this->importId,
                count($valid) - count($duplicates),
                $progress->processed($this->importId),
                $progress->failed($this->importId),
            ));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Import chunk failed', [
            'import_id' => $this->importId,
            'chunk' => $this->chunk,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Inserts valid rows, keeping the existing row when an id is already taken.
     *
     * @param  array<int|string, ValidatedRow>  $valid  keyed by id
     * @return array<int, string> row number => duplicate message
     */
    private function insert(array $valid): array
    {
        if ($valid === []) {
            return [];
        }

        // Same key order in every statement => concurrent chunks can't deadlock each other
        uksort($valid, fn (int|string $a, int|string $b): int => [strlen((string) $a), (string) $a] <=> [strlen((string) $b), (string) $b]);

        $records = [];
        foreach ($valid as $row) {
            $records[] = [
                'id' => $row->id,
                'name' => $row->name,
                'date' => $row->date,
                'import_id' => $this->importId,
                'row_number' => $row->line,
            ];
        }

        // INSERT ... ON CONFLICT DO NOTHING: one atomic statement, the first row imported wins
        DB::table('rows')->insertOrIgnore($records);

        // Every id now exists. Rows created by another import or another line are duplicates;
        // rows carrying our import id and row number are ours (possibly from an earlier attempt).
        $owners = DB::table('rows')
            ->whereIn('id', array_map('strval', array_keys($valid)))
            ->get(['id', 'import_id', 'row_number']);

        $duplicates = [];
        foreach ($owners as $owner) {
            $row = $valid[$owner->id];

            if ($owner->import_id === $this->importId && (int) $owner->row_number === $row->line) {
                continue;
            }

            $duplicates[$row->line] = $owner->import_id === $this->importId
                ? "duplicate id {$row->id} (first seen in row {$owner->row_number})"
                : "duplicate id {$row->id} (already imported earlier)";
        }

        return $duplicates;
    }
}
