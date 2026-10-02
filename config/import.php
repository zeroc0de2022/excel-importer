<?php

declare(strict_types=1);

return [

    // Rows per chunk job (the task requires 1000)
    'chunk_size' => (int) env('IMPORT_CHUNK_SIZE', 1000),

    // Upload limit in kilobytes
    'max_file_kb' => (int) env('IMPORT_MAX_FILE_KB', 20480),

    // Data rows allowed per file, 0 = unlimited (useful for a public demo)
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 0),

    // Imports older than this are removed by the nightly `imports:prune`
    'keep_days' => (int) env('IMPORT_KEEP_DAYS', 7),

    // Also delete the rows those imports created (keeps a public demo's table small)
    'prune_rows' => (bool) env('IMPORT_PRUNE_ROWS', false),

];
