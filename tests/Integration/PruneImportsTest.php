<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\ImportProgress;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_old_imports_with_their_files_and_keeps_rows(): void
    {
        Storage::fake();
        $progress = app(ImportProgress::class);

        $old = Import::create(['file_name' => 'old.xlsx', 'file_path' => 'x']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        Storage::put("imports/{$old->id}/source.xlsx", 'data');
        $progress->recordChunk($old->id, 0, 1, 0, []);
        DB::table('rows')->insert(['id' => '1', 'name' => 'John', 'date' => '2000-01-01', 'import_id' => $old->id, 'row_number' => 2]);

        $recent = Import::create(['file_name' => 'new.xlsx', 'file_path' => 'y']);

        $this->assertSame(0, Artisan::call('imports:prune', ['--days' => 7]));

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        Storage::assertMissing("imports/{$old->id}/source.xlsx");
        $this->assertSame(0, $progress->processed($old->id));
        // Imported data outlives the import record
        $this->assertNull(DB::table('rows')->where('id', '1')->value('import_id'));
    }

    public function test_can_delete_the_rows_of_pruned_imports_too(): void
    {
        Storage::fake();

        $old = Import::create(['file_name' => 'old.xlsx', 'file_path' => 'x']);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        DB::table('rows')->insert([
            ['id' => '1', 'name' => 'John', 'date' => '2000-01-01', 'import_id' => $old->id, 'row_number' => 2],
            ['id' => '2', 'name' => 'Mary', 'date' => '2000-01-01', 'import_id' => null, 'row_number' => 2],
        ]);

        $this->assertSame(0, Artisan::call('imports:prune', ['--days' => 7, '--with-rows' => true]));

        $this->assertSame(['2'], DB::table('rows')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }
}
