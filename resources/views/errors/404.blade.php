@php
    $errorOrg = $currentOrganization ?? null;
    if (! $errorOrg && request()->segment(1) === 'org' && request()->segment(2)) {
        try {
            $errorOrg = \App\Models\Organization::where('slug', request()->segment(2))->first();
        } catch (\Exception $e) {
            //
        }
    }
    $homeUrl = $errorOrg
        ? route('organization.home', ['organization' => $errorOrg])
        : (auth()->check() ? route('dashboard') : url('/'));
    $orgName = $errorOrg?->name ?? ($brandOrganizationName ?? config('app.name'));
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — {{ __('errors.404_title') }} · {{ $orgName }}</title>
    <link rel="icon" href="{{ asset('brand/bouclepro-symbol-64.png') }}">
    @vite(['resources/css/app.css'])
    <style>
        body {
            background-color: #030712;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
        }
    </style>
</head>
<body>
    <div class="mx-auto w-full max-w-md px-6 py-12 text-center">
        <img
            src="{{ $brandLogoUrl }}"
            alt="BouclePro"
            class="mx-auto w-28 h-28 sm:w-36 sm:h-36 opacity-90 mb-8"
        >

        <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-white mb-4">
            404
        </h1>

        <p class="text-lg sm:text-xl text-gray-400 leading-relaxed mb-10">
            {{ __('errors.404_message') }}
        </p>

        <form action="{{ url('/search') }}" method="GET" role="search" class="mb-10">
            <label for="error-search" class="sr-only">{{ __('errors.404_search') }}</label>
            <div class="relative">
                <input
                    id="error-search"
                    type="text"
                    name="q"
                    placeholder="{{ __('errors.404_search') }}"
                    class="w-full rounded-xl border border-gray-700 bg-gray-900 px-4 py-3 pl-11 text-sm text-white placeholder-gray-500 shadow-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition"
                >
                <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
            </div>
        </form>

        <a
            href="{{ $homeUrl }}"
            class="inline-flex items-center gap-2 text-sm font-medium text-indigo-400 hover:text-indigo-300 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            {{ __('errors.404_back_home') }}
        </a>
    </div>
</body>
</html>
