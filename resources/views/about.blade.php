<x-app-layout>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        .about-caveat { font-family: 'Caveat', cursive; }
    </style>

    <div class="min-h-screen bg-gradient-to-br from-orange-50 via-white to-indigo-50 py-12 dark:from-gray-950 dark:via-gray-900 dark:to-indigo-950">
        <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-[2rem] bg-white/85 p-8 shadow-xl shadow-indigo-100/60 ring-1 ring-white/70 backdrop-blur dark:bg-gray-900/85 dark:shadow-black/20 dark:ring-gray-800 md:p-12">
                <p class="text-sm font-bold uppercase tracking-[0.28em] text-indigo-600 dark:text-indigo-300">{{ __('about.eyebrow') }}</p>
                <h1 class="mt-4 max-w-4xl text-4xl font-black tracking-tight text-gray-950 dark:text-white md:text-6xl">{{ __('about.title') }}</h1>
                <p class="mt-6 max-w-3xl text-lg leading-8 text-gray-700 dark:text-gray-200">{{ __('about.intro') }}</p>
                <p class="mt-5 inline-flex rounded-full bg-indigo-100 px-5 py-2 text-sm font-bold text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-200">{{ __('about.ai_line') }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('partenaires.request.create') }}" class="rounded-full bg-indigo-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">{{ __('about.cta_primary') }}</a>
                    <a href="https://bouclepro.com/demo" class="rounded-full bg-white px-6 py-3 text-sm font-bold text-indigo-700 ring-1 ring-indigo-100 transition hover:bg-indigo-50 dark:bg-gray-800 dark:text-indigo-200 dark:ring-gray-700">{{ __('about.cta_secondary') }}</a>
                </div>
            </section>

            <div class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach(__('about.sections') as $section)
                    <section class="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-gray-100 dark:bg-gray-900 dark:ring-gray-800">
                        <h2 class="about-caveat text-3xl font-bold text-gray-950 dark:text-white">{{ $section['title'] }}</h2>
                        <p class="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">{{ $section['body'] }}</p>
                        <ul class="mt-5 space-y-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                            @foreach($section['items'] as $item)
                                <li class="flex gap-2"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500"></span>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            <section class="mt-10 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-gray-100 dark:bg-gray-900 dark:ring-gray-800 md:p-8">
                <h2 class="about-caveat text-4xl font-bold text-gray-950 dark:text-white">{{ __('about.comparison_title') }}</h2>
                <p class="mt-3 max-w-4xl text-sm leading-7 text-gray-600 dark:text-gray-300">{{ __('about.comparison_intro') }}</p>
                <div class="mt-6 overflow-hidden rounded-2xl border border-gray-100 dark:border-gray-800">
                    @foreach(__('about.comparison') as $row)
                        <div class="grid gap-4 border-b border-gray-100 p-4 last:border-b-0 dark:border-gray-800 md:grid-cols-[0.9fr_1.2fr_1.2fr]">
                            <strong class="text-gray-950 dark:text-white">{{ $row['need'] }}</strong>
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $row['others'] }}</span>
                            <span class="text-sm font-semibold text-indigo-700 dark:text-indigo-200">{{ $row['bouclepro'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="mt-10 grid gap-6 md:grid-cols-3">
                <section class="rounded-3xl bg-white p-6 ring-1 ring-gray-100 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="about-caveat text-3xl font-bold text-gray-950 dark:text-white">{{ __('about.audience_title') }}</h2>
                    <p class="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">{{ __('about.audience') }}</p>
                </section>
                <section class="rounded-3xl bg-white p-6 ring-1 ring-gray-100 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="about-caveat text-3xl font-bold text-gray-950 dark:text-white">{{ __('about.cyberworkers_title') }}</h2>
                    <p class="mt-4 text-sm leading-7 text-gray-600 dark:text-gray-300">{{ __('about.cyberworkers') }}</p>
                </section>
                <section class="rounded-3xl bg-indigo-600 p-6 text-white shadow-lg shadow-indigo-500/25">
                    <h2 class="about-caveat text-3xl font-bold">{{ __('about.closing_title') }}</h2>
                    <p class="mt-4 text-sm leading-7 text-indigo-50">{{ __('about.closing') }}</p>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
