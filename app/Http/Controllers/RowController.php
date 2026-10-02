<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Row;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RowController extends Controller
{
    /**
     * Imported rows grouped by date: { "18.12.1990": [ {id, name, date}, ... ], ... }.
     *
     * Paginated by rows (ordered by date), so one date can continue on the next page.
     * That keeps each page a predictable size even when one date has thousands of rows.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 100), 1), 1000);

        $page = Row::query()
            ->orderBy('date')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->groupBy(fn (Row $row): string => $row->date->format('d.m.Y')),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }
}
