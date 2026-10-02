<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Events\RowsCreated;
use App\Import\ImportProgress;
use App\Import\ImportService;
use App\Jobs\ProcessImportChunk;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSpreadsheets;
use Tests\TestCase;

/**
 * Runs the real pipeline (Bus::batch, jobs, Postgres, Redis) on the sync queue.
 */
class ImportPipelineTest extends TestCase
{
    use CreatesSpreadsheets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Event::fake([RowsCreated::class, ImportFinished::class]);

        // Small chunks, so this small file spreads over several chunk jobs
        config(['import.chunk_size' => 2]);
    }

    public function test_imports_a_file_end_to_end(): void
    {
        // A row from an earlier import that this file will collide with
        DB::table('rows')->insert(['id' => '100', 'name' => 'Existing', 'date' => '2000-01-01', 'row_number' => 2]);

        $import = $this->startImport([
            ['id', 'name', 'date'],
            ['1', 'John Smith', '01.01.2000'],     // 2: ok
            ['2', 'Anna', '29.02.2023'],           // 3: not a leap year
            ['-5', 'Bob3', '31.04.2020'],          // 4: everything wrong
            ['1', 'Someone Else', '02.02.2000'],   // 5: duplicate of row 2 (other chunk)
            ['100', 'Newcomer', '03.03.2000'],     // 6: duplicate of an existing row
            ['3', 'Carl', '03.03.2000'],           // 7: ok
            ['4', 'Dan', '04.04.2000'],            // 8: ok
            ['4', 'Dan Again', '04.04.2000'],      // 9: duplicate of row 8 (same chunk)
        ]);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(8, $import->total_rows);
        $this->assertSame(8, $import->processed_rows);
        $this->assertSame(5, $import->failed_rows);
        $this->assertNotNull($import->finished_at);

        // The first row with an id wins; existing rows are never replaced
        $this->assertSame(
            ['1' => 'John Smith', '3' => 'Carl', '4' => 'Dan', '100' => 'Existing'],
            DB::table('rows')->orderBy('id')->pluck('name', 'id')->all(),
        );

        $this->assertSame(implode("\n", [
            '3 - date must be a valid date in d.m.Y format',
            '4 - id must be an unsigned big integer, name must contain only English letters and spaces, date must be a valid date in d.m.Y format',
            '5 - duplicate id 1 (first seen in row 2)',
            '6 - duplicate id 100 (already imported earlier)',
            '9 - duplicate id 4 (first seen in row 8)',
        ])."\n", Storage::get((string) $import->report_path));

        // Progress per the task: a Redis key per import with the processed row count
        $this->assertSame(8, app(ImportProgress::class)->processed($import->id));

        Event::assertDispatchedTimes(RowsCreated::class, 4);
        Event::assertDispatched(ImportFinished::class, fn (ImportFinished $e) => $e->importId === $import->id && $e->status === 'completed');
    }

    public function test_an_empty_file_completes_with_an_empty_report(): void
    {
        $import = $this->startImport([['id', 'name', 'date']])->refresh();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(0, $import->total_rows);
        $this->assertSame('', Storage::get((string) $import->report_path));
    }

    public function test_stops_at_the_row_limit(): void
    {
        config(['import.max_rows' => 2]);

        $import = $this->startImport([
            ['id', 'name', 'date'],
            ['1', 'A', '01.01.2000'],
            ['2', 'B', '01.01.2000'],
            ['3', 'C', '01.01.2000'],
        ])->refresh();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, DB::table('rows')->count());
        $this->assertStringContainsString('more than 2 rows', (string) $import->error);
    }

    public function test_a_retried_chunk_is_counted_once(): void
    {
        $import = Import::create(['file_name' => 'x.xlsx', 'file_path' => 'x']);
        $job = new ProcessImportChunk($import->id, 0, [
            2 => ['1', 'John', '01.01.2000'],
            3 => ['2', 'Bad1', '01.01.2000'],
        ]);

        dispatch_sync($job);
        dispatch_sync($job); // e.g. the worker died after finishing, and the job ran again

        $progress = app(ImportProgress::class);
        $this->assertSame(2, $progress->processed($import->id));
        $this->assertSame(1, $progress->failed($import->id));
        $this->assertSame(1, DB::table('rows')->count());
        Event::assertDispatchedTimes(RowsCreated::class, 1);
    }

    public function test_a_chunk_retried_after_saving_rows_but_before_counting_does_not_report_its_own_rows_as_duplicates(): void
    {
        $import = Import::create(['file_name' => 'x.xlsx', 'file_path' => 'x']);
        $job = new ProcessImportChunk($import->id, 0, [
            2 => ['1', 'John', '01.01.2000'],
            3 => ['2', 'Mary', '01.01.2000'],
        ]);

        dispatch_sync($job);

        // Simulate a crash between the database insert and the Redis update
        app(ImportProgress::class)->forget($import->id);

        dispatch_sync($job);

        $progress = app(ImportProgress::class);
        $this->assertSame(2, $progress->processed($import->id));
        $this->assertSame(0, $progress->failed($import->id));
        $this->assertSame([], iterator_to_array($progress->errorLines($import->id)));
        $this->assertSame(2, DB::table('rows')->count());
    }

    public function test_failed_jobs_mark_the_import_as_failed(): void
    {
        $import = Import::create(['file_name' => 'x.xlsx', 'file_path' => 'x', 'status' => ImportStatus::Processing]);

        app(ImportService::class)->finish($import->id, failedJobs: 1);

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('1 job(s) failed', (string) $import->error);
        Storage::assertExists((string) $import->report_path);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function startImport(array $rows): Import
    {
        $file = new UploadedFile($this->makeXlsx($rows), 'people.xlsx', null, null, true);

        return app(ImportService::class)->start($file);
    }
}
