<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessExcelChunk;
use Illuminate\Support\Facades\Redis;
use PhpOffice\PhpSpreadsheet\IOFactory;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx'
        ]);

        $path = $request->file('file')->store('uploads');
        $fullPath = storage_path("app/{$path}");

        $spreadsheet = IOFactory::load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $rows = array_slice($rows, 1); // Пропустить заголовок
        $chunkSize = 1000;
        $hash = md5($path);
        Redis::set("import_progress:$hash", 0);

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            dispatch(new ProcessExcelChunk($chunk, $hash));
        }

        return response()->json(['message' => 'Импорт запущен', 'progress_key' => $hash]);
    }

    public function progress($key)
    {
        return response()->json([
            'processed' => Redis::get("import_progress:$key") ?? 0
        ]);
    }
}

