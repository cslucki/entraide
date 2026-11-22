{{--
    L'ecran d'UN plugin de Boucle — super-admin uniquement. (TASK-1879)

    Ce qu'il est, son etat, la configuration IA PLATEFORME s'il porte des
    assistants (Pour / Contre), puis sa disponibilite Organization par
    Organization. Les blocs sont des partials partages : la Veille et
    Pour / Contre montrent la meme liste d'Organizations, avec le meme geste.
--}}
<x-admin-layout :title="$plugin['label'].' — '.__('loops.plugins_admin_title')">
    <div class="mx-auto max-w-4xl px-4 py-8">

        <a href="{{ route('admin.loop-plugins') }}" data-action="back-to-catalogue"
           class="inline-flex min-h-[44px] items-center text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
            ← {{ __('loops.plugins_admin_title') }}
        </a>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300" role="alert">
                {{ session('error') }}
            </div>
        @endif

        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800"
                 data-plugin="{{ $plugin['key'] }}">

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $plugin['label'] }}</h1>
                    <p class="mt-0.5 font-mono text-xs text-gray-400">{{ $plugin['key'] }}</p>
                    @if($plugin['description'])
                        <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ $plugin['description'] }}</p>
                    @endif
                </div>

                <span data-plugin-status="{{ $plugin['status'] }}"
                      class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide
                             {{ $plugin['experimental']
                                ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'
                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' }}">
                    {{ __('loops.plugins_admin_status_'.$plugin['status']) }}
                </span>
            </div>

            @if($plugin['experimental'])
                <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    {{ __('loops.plugins_admin_experimental_notice') }}
                </p>
            @endif

            {{-- La configuration IA PLATEFORME n'existe que pour un plugin qui
                 DECLARE des assistants (`has_assistants`). --}}
            @if($hasAssistants)
                @include('admin.loop-plugins.partials.models')
            @endif

            @include('admin.loop-plugins.partials.availability')
        </section>
    </div>
</x-admin-layout>
