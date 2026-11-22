{{--
    Le catalogue des plugins de Boucle — super-admin uniquement.

    TASK-1879. Une ligne par plugin : ce qu'il est, son etat, combien
    d'Organizations l'ont, et un lien vers SON ecran. La disponibilite par
    Organization et la configuration IA vivent sur la page detail
    (`admin.loop-plugins.show`). Surface plateforme : aucune Boucle, aucun
    membre, aucun document.
--}}
<x-admin-layout :title="__('loops.plugins_admin_title')">
    <div class="mx-auto max-w-4xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.plugins_admin_title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.plugins_admin_intro') }}</p>
        </header>

        <div class="grid gap-4 sm:grid-cols-2" data-section="plugin-catalogue">
            @foreach($plugins as $plugin)
                <a href="{{ route('admin.loop-plugins.show', $plugin['slug']) }}"
                   data-plugin="{{ $plugin['key'] }}"
                   class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-indigo-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-700">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-gray-900 group-hover:text-indigo-700 dark:text-gray-100 dark:group-hover:text-indigo-300">{{ $plugin['label'] }}</h2>
                            {{-- La cle technique : c'est elle que portent les donnees. --}}
                            <p class="mt-0.5 font-mono text-xs text-gray-400">{{ $plugin['key'] }}</p>
                        </div>
                        <span data-plugin-status="{{ $plugin['status'] }}"
                              class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide
                                     {{ $plugin['experimental']
                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300'
                                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300' }}">
                            {{ __('loops.plugins_admin_status_'.$plugin['status']) }}
                        </span>
                    </div>

                    @if($plugin['description'])
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $plugin['description'] }}</p>
                    @endif

                    <div class="mt-auto flex items-center justify-between gap-2 pt-4 text-xs text-gray-500 dark:text-gray-400">
                        <span data-plugin-count>
                            {{ __('loops.plugins_admin_organizations') }} :
                            {{ __('loops.plugins_admin_enabled_count', [
                                'count' => $plugin['enabled_count'],
                                'total' => $organizations->count(),
                            ]) }}
                        </span>
                        <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ __('loops.plugins_admin_open') }} →</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</x-admin-layout>
