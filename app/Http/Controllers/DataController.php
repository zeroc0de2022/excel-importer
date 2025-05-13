<?php

namespace App\Http\Controllers;

use App\Models\Row;

class DataController extends Controller
{
    public function index()
    {
        $grouped = Row::all()->groupBy(function ($row) {
            return $row->date->format('d.m.Y');
        });

        return response()->json($grouped);
    }
}

