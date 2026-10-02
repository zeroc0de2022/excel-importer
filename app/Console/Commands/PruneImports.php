<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Import\ImportService;
use App\Models\Import;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('imports:prune
    {--days= : Delete imports older than this many days (default: config import.keep_days)}
    {--with-rows : Also delete the rows those imports created (default: config import.prune_rows)}')]
#[Description('Delete old imports with their uploaded files and reports')]
class PruneImports extends Command
{
    public function handle(ImportService $imports): int
    {
        $days = (int) ($this->option('days') ?? config('import.keep_days'));
        $withRows = $this->option('with-rows') || config('import.prune_rows');
        $deleted = 0;

        Import::where('created_at', '<', now()->subDays($days))
            ->lazyById()
            ->each(function (Import $import) use ($imports, $withRows, &$deleted): void {
                $imports->delete($import, $withRows);
                $deleted++;
            });

        $this->info("Deleted {$deleted} import(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
