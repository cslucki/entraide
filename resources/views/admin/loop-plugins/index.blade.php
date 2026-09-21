{{--
    Ou chaque plugin de Boucle est disponible — super-admin uniquement.

    TASK-1614 / SLICE A. Une carte par plugin : ce qu'il est, son etat, et la
    liste des Organizations avec un interrupteur chacune. L'ecran ne montre
    QUE des Organizations — leur nom et leur slug —, jamais une Boucle, un
    membre ou un document : c'est une surface plateforme, pas une porte vers
    un tenant.

    Chaque interrupteur est son PROPRE formulaire, avec son organization_id.
    Un seul formulaire global aurait laisse croire qu'un geste peut porter sur
    plusieurs Organizations a la fois — ce qui n'existe pas ici.
--}}
<x-admin-layout :title="__('loops.plugins_admin_title')">
    <div class="mx-auto max-w-4xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.plugins_admin_title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.plugins_admin_intro') }}</p>
        </header>

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

        <div class="space-y-6">
            @foreach($plugins as $plugin)
                <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800"
                         data-plugin="{{ $plugin['key'] }}">

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $plugin['label'] }}</h2>
                            {{-- La cle technique : c'est elle que portent les donnees, et
                                 le SuperAdmin doit pouvoir la nommer dans un ticket. --}}
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

                    {{-- ── Configuration IA PLATEFORME (TASK-1617) ──────────────
                         Quel modele OpenRouter sert quel assistant. C'est un
                         reglage d'INFRASTRUCTURE, decide une fois pour toute la
                         plateforme — a ne pas confondre avec les POSTURES, qui
                         sont Loop-scoped (TASK-1616). --}}
                    @if($plugin['key'] === 'multi_ai_assistants')
                        <div class="mt-5 rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40"
                             data-section="plugin-models">

                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('loops.plugins_models_title') }}
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.plugins_models_intro') }}</p>

                            {{-- L'etat du catalogue, dit en toutes lettres : un
                                 releve echoue ne doit pas ressembler a « aucun
                                 modele gratuit n'existe ». --}}
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs {{ $catalogState['ok'] ? 'text-gray-500 dark:text-gray-400' : 'text-red-600 dark:text-red-400' }}"
                                   data-catalog-state="{{ $catalogState['ok'] ? 'ok' : 'failed' }}">
                                    @if(! $catalogState['ok'])
                                        {{ __('loops.plugins_models_catalog_failed', ['reason' => $catalogState['error'] ?? '—']) }}
                                    @elseif($catalogState['fetched_at'])
                                        {{ __('loops.plugins_models_catalog_ok', [
                                            'count' => count($freeModels),
                                            'date' => \Illuminate\Support\Carbon::parse($catalogState['fetched_at'])->format('d/m/Y H:i'),
                                        ]) }}
                                    @else
                                        {{ __('loops.plugins_models_catalog_never') }}
                                    @endif
                                </p>

                                <form method="POST" action="{{ route('admin.loop-plugins.models.refresh', $plugin['key']) }}">
                                    @csrf
                                    <button type="submit" data-action="refresh-models"
                                            class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                        {{ __('loops.plugins_models_refresh') }}
                                    </button>
                                </form>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach($assistantModels as $assistant)
                                    @php
                                        // TASK-1585 : ne jamais nommer une variable
                                        // de vue `$loop`, Blade se la reserve.
                                        $etat = $assistant['model_slug'] === null ? 'unset'
                                            : (! $assistant['still_free'] ? 'gone'
                                            : (! $assistant['proof_fresh'] ? 'stale' : 'ok'));
                                    @endphp

                                    <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
                                         data-assistant-model="{{ $assistant['assistant_key'] }}">

                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold uppercase tracking-wide text-gray-800 dark:text-gray-100">{{ $assistant['label'] }}</p>
                                            <span data-model-status="{{ $etat }}"
                                                  class="rounded-full px-2.5 py-1 text-[11px] font-medium
                                                         {{ $etat === 'ok'
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                                            : ($etat === 'unset'
                                                               ? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
                                                               : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300') }}">
                                                {{ __('loops.plugins_models_status_'.$etat) }}
                                            </span>
                                        </div>

                                        @if($assistant['model_slug'])
                                            <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $assistant['model_slug'] }}</p>
                                            @if($assistant['verified_free_at'])
                                                <p class="text-[11px] text-gray-400">
                                                    {{ __('loops.plugins_models_free_verified', ['date' => $assistant['verified_free_at']->format('d/m/Y H:i')]) }}
                                                    @if($assistant['catalog_entry']['context_length'] ?? null)
                                                        · {{ __('loops.plugins_models_context', ['tokens' => number_format($assistant['catalog_entry']['context_length'], 0, ',', ' ')]) }}
                                                    @endif
                                                </p>
                                            @endif
                                        @endif

                                        <form method="POST" action="{{ route('admin.loop-plugins.models.update', $plugin['key']) }}"
                                              class="mt-2 flex flex-wrap items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="assistant_key" value="{{ $assistant['assistant_key'] }}">

                                            <select name="model_slug" required
                                                    data-model-select="{{ $assistant['assistant_key'] }}"
                                                    class="min-h-[44px] flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                                <option value="">{{ __('loops.plugins_models_choose') }}</option>
                                                @forelse($freeModels as $slug => $modele)
                                                    <option value="{{ $slug }}" @selected($assistant['model_slug'] === $slug)>
                                                        {{ $modele['name'] }} — {{ $slug }}
                                                    </option>
                                                @empty
                                                    <option value="" disabled>{{ __('loops.plugins_models_none') }}</option>
                                                @endforelse
                                            </select>

                                            <button type="submit"
                                                    class="min-h-[44px] rounded-xl bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                                                {{ __('loops.plugins_assistants_save') }}
                                            </button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 text-[11px] leading-5 text-gray-400">{{ __('loops.plugins_models_fail_closed') }}</p>
                        </div>
                    @endif

                    <div class="mt-5">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('loops.plugins_admin_organizations') }}
                            </h3>
                            <span class="text-xs text-gray-400" data-plugin-count>
                                {{ __('loops.plugins_admin_enabled_count', [
                                    'count' => $plugin['enabled_count'],
                                    'total' => $organizations->count(),
                                ]) }}
                            </span>
                        </div>

                        @if($organizations->isEmpty())
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.plugins_admin_no_organizations') }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($organizations as $organization)
                                    @php
                                        // TASK-1611 : un bloc, jamais deux directives PHP en
                                        // ligne — Blade recopierait alors en PHP tout ce qui se
                                        // trouve entre les deux. Et la FORME de la directive ne
                                        // s'ecrit pas non plus dans ce commentaire : le
                                        // compilateur la reconnaitrait ici aussi.
                                        $actif = $plugin['availability'][$organization->id] ?? false;
                                        $decision = $plugin['decisions'][$organization->id] ?? null;
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 py-3"
                                        data-organization="{{ $organization->id }}">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $organization->name }}</p>
                                            <p class="truncate font-mono text-xs text-gray-400">{{ $organization->slug }}</p>
                                            @if($decision?->updated_at)
                                                <p class="mt-0.5 text-xs text-gray-400">
                                                    {{ $decision->updatedBy
                                                        ? __('loops.plugins_admin_last_decision', [
                                                            'date' => $decision->updated_at->format('d/m/Y H:i'),
                                                            'author' => $decision->updatedBy->name,
                                                          ])
                                                        : __('loops.plugins_admin_last_decision_anonymous', [
                                                            'date' => $decision->updated_at->format('d/m/Y H:i'),
                                                          ]) }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="flex shrink-0 items-center gap-3">
                                            <span data-availability="{{ $actif ? 'on' : 'off' }}"
                                                  class="rounded-full px-2.5 py-1 text-xs font-medium
                                                         {{ $actif
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                                            : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                                {{ $actif ? __('loops.plugins_admin_available') : __('loops.plugins_admin_unavailable') }}
                                            </span>

                                            {{-- Un formulaire par Organization : le geste ne peut
                                                 porter que sur celle-ci. --}}
                                            <form method="POST" action="{{ route('admin.loop-plugins.update', $plugin['key']) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="organization_id" value="{{ $organization->id }}">
                                                <input type="hidden" name="available" value="{{ $actif ? 0 : 1 }}">
                                                <button type="submit"
                                                        data-action="{{ $actif ? 'disable' : 'enable' }}"
                                                        class="min-h-[44px] rounded-xl px-4 text-sm font-medium
                                                               {{ $actif
                                                                  ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'
                                                                  : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
                                                    {{ $actif ? __('loops.plugins_admin_disable') : __('loops.plugins_admin_enable') }}
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-admin-layout>
