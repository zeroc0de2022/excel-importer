<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ImportStatus $status
 * @property string $file_name
 * @property string $file_path
 * @property string|null $batch_id
 * @property int|null $total_rows
 * @property int $processed_rows
 * @property int $failed_rows
 * @property string|null $report_path
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Import extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'file_name',
        'file_path',
        'batch_id',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'report_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Row, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(Row::class);
    }
}
