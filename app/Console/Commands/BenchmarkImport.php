<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Import\ImportService;
use App\Models\Import;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

#[Signature('import:benchmark
    {--rows=100000 : Rows in the generated file}
    {--fresh : Delete all imported rows first, so every run inserts the same data}')]
#[Description('Run the whole import pipeline in this process and report time and peak memory')]
class BenchmarkImport extends Command
{
    public function handle(ImportService $imports): int
    {
        $rows = (int) $this->option('rows');
        $path = storage_path("app/demo/bench-{$rows}.xlsx");

        if (! file_exists($path)) {
            $this->call('demo:generate-file', ['--rows' => $rows, '--seed' => 1, '--path' => $path]);
        }

        if ($this->option('fresh')) {
            DB::table('rows')->truncate();
        }

        // The sync queue runs every job right here, one after another:
        // the same work a single queue worker does, but measurable from one process.
        config(['queue.default' => 'sync']);
        memory_reset_peak_usage();
        $started = hrtime(true);

        $import = $imports->start(new UploadedFile($path, basename($path), test: true));

        $seconds = (hrtime(true) - $started) / 1e9;
        $import = Import::findOrFail($import->id);

        $this->table(['Metric', 'Value'], [
            ['Rows in file', number_format((int) $import->total_rows)],
            ['Imported', number_format($import->processed_rows - $import->failed_rows)],
            ['Errors + duplicates', number_format($import->failed_rows)],
            ['Time', sprintf('%.2f s', $seconds)],
            ['Throughput', number_format($import->processed_rows / $seconds).' rows/s'],
            ['Peak memory', sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576)],
            ['Status', $import->status->value],
        ]);

        return self::SUCCESS;
    }
}
