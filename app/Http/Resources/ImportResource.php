<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Import\ImportService;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counters = app(ImportService::class)->counters($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'file_name' => $this->file_name,
            'total_rows' => $this->total_rows,
            'processed_rows' => $counters['processed'],
            'failed_rows' => $counters['failed'],
            'imported_rows' => $counters['processed'] - $counters['failed'],
            // null while the file is still being read and the total is unknown
            'progress' => $this->total_rows ? min(100, (int) floor($counters['processed'] * 100 / $this->total_rows)) : null,
            'error' => $this->error,
            'report_url' => $this->report_path ? route('imports.report', $this->id) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
