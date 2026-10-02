<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Import\ImportService;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(Import::latest()->paginate(20));
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        $import = $this->imports->start($request->file('file'));

        // 202 Accepted: the work continues in the queue
        return ImportResource::make($import)->response()->setStatusCode(202);
    }

    public function show(Import $import): ImportResource
    {
        return ImportResource::make($import);
    }

    public function report(Import $import): StreamedResponse
    {
        abort_unless($import->report_path && Storage::exists($import->report_path), 404, 'The report is not ready yet.');

        return Storage::download($import->report_path, 'result.txt', ['Content-Type' => 'text/plain']);
    }
}
