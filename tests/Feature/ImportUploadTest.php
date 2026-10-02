<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ReadImportFile;
use App\Models\Import;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSpreadsheets;
use Tests\TestCase;

class ImportUploadTest extends TestCase
{
    use CreatesSpreadsheets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        Bus::fake();
    }

    public function test_upload_stores_the_file_and_dispatches_a_batch(): void
    {
        $response = $this->post('/api/imports', ['file' => $this->xlsxUpload()], $this->basicAuth() + ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.file_name', 'people.xlsx')
            ->assertJsonPath('data.progress', null);

        $import = Import::sole();
        $this->assertSame(ImportStatus::Pending, $import->status);
        $this->assertNotNull($import->batch_id);
        Storage::assertExists("imports/{$import->id}/source.xlsx");

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof ReadImportFile
            && $batch->jobs->first()->importId === $import->id
            && $batch->allowsFailures());
    }

    public function test_file_is_required(): void
    {
        $this->postJson('/api/imports', [], $this->basicAuth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_rejects_other_file_types(): void
    {
        $file = UploadedFile::fake()->createWithContent('people.csv', "id,name,date\n1,John,01.01.2000\n");

        $this->postJson('/api/imports', ['file' => $file], $this->basicAuth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        Bus::assertNothingBatched();
    }

    public function test_rejects_a_renamed_file_that_is_not_really_xlsx(): void
    {
        // A real file, because fake uploads guess the MIME type from the name, not the content
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'just some text');
        $file = new UploadedFile($path, 'people.xlsx', null, null, true);

        $this->postJson('/api/imports', ['file' => $file], $this->basicAuth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_rejects_files_over_the_size_limit(): void
    {
        config(['import.max_file_kb' => 1]);

        $file = $this->xlsxUpload(rows: 500);
        $this->assertGreaterThan(1024, $file->getSize());

        $this->postJson('/api/imports', ['file' => $file], $this->basicAuth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_uploads_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/api/imports', ['file' => $this->xlsxUpload()], $this->basicAuth() + ['Accept' => 'application/json'])
                ->assertAccepted();
        }

        $this->post('/api/imports', ['file' => $this->xlsxUpload()], $this->basicAuth() + ['Accept' => 'application/json'])
            ->assertTooManyRequests();
    }

    private function xlsxUpload(int $rows = 3): UploadedFile
    {
        $data = [['id', 'name', 'date']];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = [(string) $i, 'John Smith', '01.01.2000'];
        }

        return new UploadedFile($this->makeXlsx($data), 'people.xlsx', null, null, true);
    }
}
