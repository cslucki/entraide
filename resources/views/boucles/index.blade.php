<x-app-layout>
    @php
        $currentOrganization = currentOrganization();
        $organizationRouteParam = request()->route('organization') ?? $currentOrganization?->slug;

        $exampleLoops = collect([
            [
                'name' => __('loops.landing_example_1_name'),
                'description' => __('loops.landing_example_1_desc'),
            ],
            [
                'name' => __('loops.landing_example_2_name'),
                'description' => __('loops.landing_example_2_desc'),
            ],
            [
                'name' => __('loops.landing_example_3_name'),
                'description' => __('loops.landing_example_3_desc'),
            ],
        ]);

        $visibleLoops = $exampleLoops;

        $loginUrl = $organizationRouteParam && Route::has('organization.login')
            ? route('organization.login', ['organization' => $organizationRouteParam])
            : route('login');
    @endphp

    <x-page-container>
        <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">{{ __('loops.title') }}</p>
            <span class="hidden">{{ __('loops.collaboration_spaces') }}</span>
            <h1 class="mt-3 text-3xl font-bold tracking-tight text-gray-950 dark:text-gray-50 sm:text-4xl">
                {{ __('loops.landing_hero_title') }}
            </h1>
            <p class="mt-4 text-base leading-7 text-gray-600 dark:text-gray-300 sm:text-lg">
                {{ __('loops.landing_hero_desc') }}
            </p>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                @guest
                    <a href="{{ $loginUrl }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                        {{ __('loops.landing_cta_login') }}
                    </a>
                @endguest
            </div>
        </div>

        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">{{ __('loops.landing_card_context_title') }}</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('loops.landing_card_context_desc') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">{{ __('loops.landing_card_members_title') }}</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('loops.landing_card_members_desc') }}</p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-950 dark:text-gray-50">{{ __('loops.landing_card_org_title') }}</p>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('loops.landing_card_org_desc') }}</p>
            </div>
        </div>

        <section class="mt-10">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-gray-50">{{ __('loops.landing_examples_title') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('loops.landing_examples_desc') }}
                    </p>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach($visibleLoops as $visibleLoop)
                    <article class="rounded-2xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/70">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-gray-50">{{ $visibleLoop['name'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ Str::limit($visibleLoop['description'], 140) }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="mt-10 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900 sm:p-6">
            <h2 class="text-lg font-semibold text-gray-950 dark:text-gray-50">{{ __('loops.landing_not_title') }}</h2>
            <div class="mt-4 grid gap-3 text-sm text-gray-600 dark:text-gray-400 sm:grid-cols-2">
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">{{ __('loops.landing_not_chat') }}</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">{{ __('loops.landing_not_whatsapp') }}</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">{{ __('loops.landing_not_marketplace') }}</p>
                <p class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800">{{ __('loops.landing_not_chatbot') }}</p>
            </div>
        </section>

        <p class="mt-8 text-sm leading-6 text-gray-500 dark:text-gray-400">
            {{ __('loops.landing_footer') }}
        </p>
    </x-page-container>
</x-app-layout>
