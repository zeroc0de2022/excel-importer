<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[Signature('demo:generate-file
    {--rows=100000 : Number of data rows}
    {--path= : Output path (default: storage/app/demo/demo-{rows}.xlsx)}
    {--seed= : Seed for a reproducible file}')]
#[Description('Generate an xlsx file for the import, with ~3% invalid rows and ~2% duplicate ids')]
class GenerateDemoFile extends Command
{
    private const FIRST_NAMES = ['James', 'Mary', 'Robert', 'Patricia', 'John', 'Jennifer', 'Michael', 'Linda', 'David', 'Elizabeth', 'William', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica', 'Thomas', 'Sarah', 'Charles', 'Karen', 'Anna', 'Oliver', 'Emma', 'Lucas'];

    private const LAST_NAMES = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Wilson', 'Anderson', 'Taylor', 'Thomas', 'Moore', 'Jackson', 'Martin', 'Lee', 'Thompson', 'White', 'Harris', 'Clark', 'Lewis', 'Walker'];

    private const INVALID_IDS = ['-15', '12a', '3.5', '', 'abc', '18446744073709551616'];

    private const INVALID_NAMES = ['John3', 'Иван Петров', "O'Brien", '', 'Anna_Lee', 'Mary-Jane'];

    private const INVALID_DATES = ['31.02.2001', '2001-05-12', '5.5.2001', '29.02.1999', '12/10/1990', ''];

    private Randomizer $random;

    public function handle(): int
    {
        $rows = max(1, (int) $this->option('rows'));
        $path = $this->option('path') ?: storage_path("app/demo/demo-{$rows}.xlsx");
        $seed = $this->option('seed');

        $this->random = new Randomizer($seed !== null ? new Mt19937((int) $seed) : null);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['id', 'name', 'date']));

        $usedIds = [];
        $bar = $this->output->createProgressBar($rows);

        for ($i = 0; $i < $rows; $i++) {
            $writer->addRow(Row::fromValues($this->makeRow($i, $usedIds)));
            $bar->advance();
        }

        $writer->close();
        $bar->finish();

        $this->newLine();
        $this->info("Written {$rows} rows to {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $usedIds  ids written so far, to pick duplicates from
     * @return list<string>
     */
    private function makeRow(int $index, array &$usedIds): array
    {
        $roll = $this->random->getInt(1, 100);

        // ~2%: repeat an id from an earlier row
        if ($roll <= 2 && $usedIds !== []) {
            $id = $usedIds[$this->random->getInt(0, count($usedIds) - 1)];

            return [$id, $this->name(), $this->date()];
        }

        // ~1%: invalid id
        if ($roll === 3) {
            return [$this->pick(self::INVALID_IDS), $this->name(), $this->date()];
        }

        $id = (string) (1_000_000 + $index);
        $usedIds[] = $id;

        // ~2%: invalid name or date
        return match ($roll) {
            4 => [$id, $this->pick(self::INVALID_NAMES), $this->date()],
            5 => [$id, $this->name(), $this->pick(self::INVALID_DATES)],
            default => [$id, $this->name(), $this->date()],
        };
    }

    private function name(): string
    {
        return $this->pick(self::FIRST_NAMES).' '.$this->pick(self::LAST_NAMES);
    }

    private function date(): string
    {
        $timestamp = $this->random->getInt(strtotime('1950-01-01'), strtotime('2010-12-31'));

        return date('d.m.Y', $timestamp);
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(array $values): string
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }
}
