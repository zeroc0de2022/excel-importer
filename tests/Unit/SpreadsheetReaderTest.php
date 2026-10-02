<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CreatesSpreadsheets;

class SpreadsheetReaderTest extends TestCase
{
    use CreatesSpreadsheets;

    public function test_skips_the_header_and_keys_rows_by_spreadsheet_row_number(): void
    {
        $path = $this->makeXlsx([
            ['id', 'name', 'date'],
            ['1', 'John', '01.01.2000'],
            null, // empty row 3 is skipped, but numbering continues
            ['2', 'Mary', '02.02.2000'],
        ]);

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        $this->assertSame([
            2 => ['1', 'John', '01.01.2000'],
            4 => ['2', 'Mary', '02.02.2000'],
        ], $rows);
    }

    public function test_pads_missing_cells_and_ignores_extra_columns(): void
    {
        $path = $this->makeXlsx([
            ['id', 'name', 'date'],
            ['1'],
            ['2', 'Mary', '02.02.2000', 'extra'],
        ]);

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        $this->assertCount(3, $rows[2]);
        $this->assertSame('1', $rows[2][0]);
        $this->assertEmpty($rows[2][1]);
        $this->assertEmpty($rows[2][2]);
        $this->assertSame(['2', 'Mary', '02.02.2000'], $rows[3]);
    }

    public function test_keeps_numeric_cells_as_numbers(): void
    {
        $path = $this->makeXlsx([
            ['id', 'name', 'date'],
            [15, 'John', '01.01.2000'],
        ]);

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        $this->assertSame(15, $rows[2][0]);
    }
}
