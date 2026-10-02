<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by resources/js/echo.js, so the built JS doesn't depend on build-time env --}}
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <nav class="mx-auto flex max-w-5xl items-center gap-6 px-4 py-3">
            <span class="font-semibold">{{ config('app.name') }}</span>
            <a href="{{ url('/') }}" @class(['text-sm hover:text-indigo-600', 'text-indigo-600 font-medium' => request()->is('/')])>Imports</a>
            <a href="{{ url('/rows') }}" @class(['text-sm hover:text-indigo-600', 'text-indigo-600 font-medium' => request()->is('rows')])>Rows by date</a>
        </nav>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-8">
        @yield('content')
    </main>
</body>
</html>
