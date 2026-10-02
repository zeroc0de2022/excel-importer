<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nightly cleanup: old imports and their files, finished batch records, old failed jobs
Schedule::command('imports:prune')->dailyAt('03:00');
Schedule::command('queue:prune-batches --hours=48')->dailyAt('03:10');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:20');
