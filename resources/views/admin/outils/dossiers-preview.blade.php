{{-- TASK-1630 — le DEUXIEME temps : ce que la purge emporterait.
     L'ecran ne supprime rien. Il montre les nombres, dit que le geste est
     definitif, et demande une case cochee AVANT d'offrir le bouton rouge. --}}
<x-admin-layout :title="__('admin.dossiers_cleanup.preview_title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.dossiers_cleanup.preview_lead') }}</p>
    </div>

    <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
        {{ __('admin.dossiers_cleanup.preview_irreversible') }}
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            'preview_dossiers' => $preview['dossiers'],
            'preview_descendants' => $preview['descendants'],
            'preview_files' => $preview['files'],
            'preview_articles' => $preview['articles'],
            'preview_series' => $preview['series'],
            'preview_members' => $preview['members'],
        ] as $cle => $valeur)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $valeur }}</div>
                <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.dossiers_cleanup.'.$cle) }}</div>
            </div>
        @endforeach
    </div>

    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.dossiers_cleanup.preview_articles_kept') }}</p>

    <div class="mb-6">
        <h2 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.dossiers_cleanup.preview_organizations') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $organizations->pluck('name')->implode(', ') }}</p>
    </div>

    <div class="mb-6">
        <h2 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.dossiers_cleanup.preview_nodes') }}</h2>
        <ul class="rounded-xl border border-gray-200 bg-white divide-y divide-gray-100 dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
            @foreach($preview['nodes'] as $noeud)
                <li class="px-4 py-2 text-sm text-gray-700 dark:text-gray-200">
                    {{ $noeud->displayName() }}
                    <span class="ml-2 text-xs text-gray-400">{{ __('admin.dossiers_cleanup.type_'.$eligibility->type($noeud)) }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    @if($refused->isNotEmpty())
        {{-- Ce qui a ete ECARTE est dit, jamais tu : un operateur qui coche
             dix lignes et en voit partir huit doit savoir lesquelles. --}}
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
            <h2 class="mb-2 text-sm font-semibold text-amber-800 dark:text-amber-300">{{ __('admin.dossiers_cleanup.preview_refused') }}</h2>
            <ul class="list-disc pl-5 text-sm text-amber-800 dark:text-amber-300">
                @foreach($refused as $refuse)
                    <li>{{ $refuse->displayName() }} — {{ __('admin.dossiers_cleanup.protected_'.$eligibility->protectionReason($refuse)) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.outils.dossiers.purge') }}" x-data="{ confirmed: false }">
        @csrf
        @foreach($allowed as $dossier)
            <input type="hidden" name="dossiers[]" value="{{ $dossier->getKey() }}">
        @endforeach
        @foreach(['organization', 'type', 'state', 'search'] as $passthrough)
            <input type="hidden" name="{{ $passthrough }}" value="{{ $filters[$passthrough] ?? '' }}">
        @endforeach

        <label class="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-200">
            <input type="checkbox" name="confirm" value="1" x-model="confirmed" class="rounded border-gray-300 dark:border-gray-600">
            {{ __('admin.dossiers_cleanup.preview_confirm') }}
        </label>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" :disabled="! confirmed"
                    class="inline-flex min-h-11 items-center rounded-lg bg-red-600 px-5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ __('admin.dossiers_cleanup.preview_submit') }}
            </button>
            <a href="{{ route('admin.outils.dossiers', array_filter($filters)) }}"
               class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-5 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">
                {{ __('admin.dossiers_cleanup.preview_cancel') }}
            </a>
        </div>
    </form>
</x-admin-layout>
