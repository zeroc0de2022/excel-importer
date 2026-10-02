<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Import\ImportProgress;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_live_progress_from_redis_while_processing(): void
    {
        $import = Import::create([
            'file_name' => 'people.xlsx',
            'file_path' => 'imports/x/source.xlsx',
            'status' => ImportStatus::Processing,
            'total_rows' => 4000,
        ]);

        $progress = app(ImportProgress::class);
        $progress->recordChunk($import->id, 0, 1000, 10, []);
        $progress->recordChunk($import->id, 1, 1000, 5, []);

        $this->getJson("/api/imports/{$import->id}", $this->basicAuth())
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.total_rows', 4000)
            ->assertJsonPath('data.processed_rows', 2000)
            ->assertJsonPath('data.failed_rows', 15)
            ->assertJsonPath('data.imported_rows', 1985)
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.report_url', null);
    }

    public function test_shows_final_counters_from_the_database_when_finished(): void
    {
        $import = Import::create([
            'file_name' => 'people.xlsx',
            'file_path' => 'imports/x/source.xlsx',
            'status' => ImportStatus::Completed,
            'total_rows' => 10,
            'processed_rows' => 10,
            'failed_rows' => 3,
            'report_path' => 'imports/x/result.txt',
        ]);

        $this->getJson("/api/imports/{$import->id}", $this->basicAuth())
            ->assertOk()
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.imported_rows', 7)
            ->assertJsonPath('data.report_url', route('imports.report', $import->id));
    }

    public function test_unknown_or_malformed_ids_return_404(): void
    {
        $this->getJson('/api/imports/'.Str::uuid(), $this->basicAuth())->assertNotFound();
        $this->getJson('/api/imports/not-a-uuid', $this->basicAuth())->assertNotFound();
    }

    public function test_lists_imports_newest_first(): void
    {
        $old = Import::create(['file_name' => 'old.xlsx', 'file_path' => 'a']);
        $old->forceFill(['created_at' => now()->subHour()])->save();
        Import::create(['file_name' => 'new.xlsx', 'file_path' => 'b']);

        $this->getJson('/api/imports', $this->basicAuth())
            ->assertOk()
            ->assertJsonPath('data.0.file_name', 'new.xlsx')
            ->assertJsonPath('data.1.file_name', 'old.xlsx')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_downloads_the_report(): void
    {
        Storage::fake();
        Storage::put('imports/x/result.txt', "3 - name must contain only English letters and spaces\n");

        $import = Import::create([
            'file_name' => 'people.xlsx',
            'file_path' => 'imports/x/source.xlsx',
            'status' => ImportStatus::Completed,
            'report_path' => 'imports/x/result.txt',
        ]);

        $response = $this->get("/api/imports/{$import->id}/report", $this->basicAuth());

        $response->assertOk()->assertDownload('result.txt');
        $this->assertSame("3 - name must contain only English letters and spaces\n", $response->streamedContent());
    }

    public function test_report_is_404_until_the_import_finishes(): void
    {
        $import = Import::create(['file_name' => 'people.xlsx', 'file_path' => 'a']);

        $this->getJson("/api/imports/{$import->id}/report", $this->basicAuth())->assertNotFound();
    }
}
