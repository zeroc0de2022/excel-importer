@extends('layouts.app')

@section('title', 'Imports')

@section('content')
    <div id="app"
         data-page="imports"
         data-sample-url="{{ asset('samples/sample-1000.xlsx') }}"
         data-max-file-mb="{{ intdiv(config('import.max_file_kb'), 1024) }}"></div>
@endsection
