<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RowsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_rows_grouped_by_date(): void
    {
        $this->insertRows([
            ['id' => '3', 'name' => 'Carl', 'date' => '2000-01-02'],
            ['id' => '1', 'name' => 'Anna', 'date' => '2000-01-01'],
            ['id' => '2', 'name' => 'Bob', 'date' => '2000-01-01'],
        ]);

        $this->getJson('/api/rows', $this->basicAuth())
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    '01.01.2000' => [
                        ['id' => '1', 'name' => 'Anna', 'date' => '01.01.2000'],
                        ['id' => '2', 'name' => 'Bob', 'date' => '01.01.2000'],
                    ],
                    '02.01.2000' => [
                        ['id' => '3', 'name' => 'Carl', 'date' => '02.01.2000'],
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 3],
                'links' => ['next' => null, 'prev' => null],
            ]);
    }

    public function test_paginates_by_rows(): void
    {
        $this->insertRows([
            ['id' => '1', 'name' => 'Anna', 'date' => '2000-01-01'],
            ['id' => '2', 'name' => 'Bob', 'date' => '2000-01-01'],
            ['id' => '3', 'name' => 'Carl', 'date' => '2000-01-02'],
        ]);

        $this->getJson('/api/rows?per_page=2&page=2', $this->basicAuth())
            ->assertOk()
            ->assertJsonPath('data', ['02.01.2000' => [['id' => '3', 'name' => 'Carl', 'date' => '02.01.2000']]])
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_page_is_capped(): void
    {
        $this->getJson('/api/rows?per_page=100000', $this->basicAuth())
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1000);
    }

    public function test_keeps_ids_beyond_php_int_max_exact(): void
    {
        $this->insertRows([['id' => '18446744073709551615', 'name' => 'Max', 'date' => '2000-01-01']]);

        $data = $this->getJson('/api/rows', $this->basicAuth())->assertOk()->json('data');

        $this->assertSame('18446744073709551615', $data['01.01.2000'][0]['id']);
    }

    /**
     * @param  list<array{id: string, name: string, date: string}>  $rows
     */
    private function insertRows(array $rows): void
    {
        DB::table('rows')->insert(array_map(fn (array $row) => $row + ['row_number' => 2], $rows));
    }
}
