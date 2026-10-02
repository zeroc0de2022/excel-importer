<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // extensions: checks the file name, mimes: checks the actual content
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:'.config('import.max_file_kb')],
        ];
    }
}
