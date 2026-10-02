<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id numeric string, may exceed PHP_INT_MAX
 * @property string $name
 * @property Carbon $date
 * @property string|null $import_id
 * @property int $row_number
 * @property Carbon $created_at
 */
class Row extends Model
{
    // The primary key is the id from the file, not an auto-increment
    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $hidden = ['import_id', 'row_number', 'created_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:d.m.Y',
        ];
    }
}
