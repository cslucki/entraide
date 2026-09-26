{{-- TASK-1648 — le Preview.

     « Un scenario doit etre comprehensible AVANT qu'une Organization
     n'existe » (CDC 11.1). Cet ecran lit le Manifest administratif et ne
     depend d'aucune sandbox chargee.

     Il ne mute rien : ni Validate, ni Approve, ni Load, ni edition. Ces
     actions arrivent en T1649 et au-dela.

     Deux sources, et il ne faut pas les confondre :
     - les COMPTEURS et le verdict viennent de `validation_summary`, calcule
       au save. Le Validator n'est jamais relance ici (CDC 11.1).
     - les OBJETS des onglets viennent du document, parse UNE fois pour cette
       seule version. --}}
<x-admin-layout :title="$version->name">
    <div class="mb-4">
        <a href="{{ route('admin.outils.scenarios') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ __('admin.scenario_manager.preview_back') }}</a>
    </div>

    <header class="mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $version->name }}</h1>

            @if($version->isLoaded())
                <span data-state="loaded" class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">{{ __('admin.scenario_manager.state_loaded') }}</span>
            @elseif($version->isValid())
                <span data-state="valid" class="inline-flex items-center rounded-full bg-green-100 px-2 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/40 dark:text-green-300">{{ __('admin.scenario_manager.state_valid') }}</span>
            @else
                <span data-state="draft" class="inline-flex items-center rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ __('admin.scenario_manager.state_draft') }}</span>
            @endif
        </div>
        <p class="mt-1 font-mono text-xs text-gray-400 dark:text-gray-500">{{ $version->scenario_key }} · v{{ $version->version }}</p>
    </header>

    @unless($preview->isReadable())
        <div class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
            <p class="text-sm font-semibold text-red-900 dark:text-red-200">{{ __('admin.scenario_manager.preview_unreadable') }}</p>
            @if($onglet === 'resume')
                <p class="mt-1 text-xs text-red-800 dark:text-red-300">{{ __('admin.scenario_manager.preview_unreadable_hint') }}</p>
            @endif
        </div>
    @endunless

    {{-- Vue d'ensemble (CDC 11.2) --}}
    <section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_overview') }}</h2>

        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
            @php
                $lignes = [
                    __('admin.scenario_manager.preview_id') => $preview->header('id'),
                    __('admin.scenario_manager.col_version') => $version->version,
                    __('admin.scenario_manager.preview_document_version') => $preview->header('version'),
                    __('admin.scenario_manager.preview_locale') => $preview->header('locale'),
                    __('admin.scenario_manager.col_usage') => __('admin.scenario_manager.usage_'.$version->usage),
                    __('admin.scenario_manager.col_origin') => __('admin.scenario_manager.origin_'.$version->origin),
                    __('admin.scenario_manager.preview_proposed_slug') => $preview->proposedSlug(),
                ];
            @endphp
            @foreach($lignes as $etiquette => $valeur)
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $etiquette }}</dt>
                    <dd class="font-mono text-sm text-gray-900 dark:text-gray-100">{{ $valeur ?: '—' }}</dd>
                </div>
            @endforeach

            <div class="sm:col-span-2 lg:col-span-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_digest') }}</dt>
                <dd class="break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ $version->digest ?: __('admin.scenario_manager.no_digest') }}</dd>
            </div>

            @if($preview->header('purpose'))
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_purpose') }}</dt>
                    <dd class="text-sm text-gray-700 dark:text-gray-300">{{ $preview->header('purpose') }}</dd>
                </div>
            @endif
        </dl>

        {{-- Mention EXPLICITE exigee par le CDC 11.2 : un chargement ne cible
             jamais une Organization existante. --}}
        <p class="mt-4 rounded-lg bg-blue-50 p-3 text-xs text-blue-900 dark:bg-blue-900/20 dark:text-blue-200">
            {{ __('admin.scenario_manager.preview_new_sandbox') }}
        </p>
    </section>

    {{-- Onglets (CDC 11.3). Des LIENS, pas du JavaScript : chaque onglet a son
         URL, donc se partage et se recharge. --}}
    <nav class="mb-4 flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach($onglets as $cle => $onglet_declare)
            <a href="{{ route('admin.outils.scenarios.show', ['version' => $version, 'onglet' => $cle]) }}"
               @class([
                   'rounded-lg px-3 py-2 text-sm',
                   'bg-indigo-600 text-white font-semibold' => $onglet === $cle,
                   'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $onglet !== $cle,
               ])
               @if($onglet === $cle) aria-current="page" @endif>
                {{ __('admin.scenario_manager.'.$onglet_declare['libelle']) }}
            </a>
        @endforeach
    </nav>

    @if($onglet === 'resume')
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_counters') }}</h2>
            <p class="mb-4 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.preview_counters_source') }}</p>

            @if($compteurs === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_no_counters') }}</p>
            @else
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
                    @foreach($compteurs as $nom => $valeur)
                        @continue(! is_scalar($valeur))
                        <div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $nom }}</dt>
                            <dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $valeur }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            {{-- Le verdict et l'etat disent deux choses differentes : un
                 brouillon peut avoir ete valide puis re-edite. Les montrer tous
                 les deux evite de lire l'un pour l'autre. --}}
            <p class="mt-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('admin.scenario_manager.preview_verdict') }} :
                <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ is_scalar($verdict) ? $verdict : __('admin.scenario_manager.preview_verdict_none') }}</span>
            </p>

            <h2 class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_errors') }}</h2>
            @if($erreurs === [])
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_no_errors') }}</p>
            @else
                {{-- CDC 11.5 : le JSON Pointer existe pour la precision
                     technique, mais il vient APRES le message lisible. --}}
                <ul class="space-y-3">
                    @foreach(array_slice($erreurs, 0, $limite) as $erreur)
                        <li class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                            <p class="text-sm text-red-900 dark:text-red-200">{{ $erreur['message'] ?? '' }}</p>
                            <p class="mt-1 font-mono text-xs text-red-700 dark:text-red-400">{{ $erreur['path'] ?? '' }} · {{ $erreur['code'] ?? '' }}</p>
                        </li>
                    @endforeach
                </ul>

                @if(count($erreurs) > $limite)
                    {{-- Le CDC 11.5 fait de cet ecran l'endroit ou l'on
                         comprend pourquoi un brouillon est invalide : couper
                         cinquante erreurs sans le dire y ferait perdre
                         exactement ce qu'on vient y chercher. --}}
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('admin.scenario_manager.preview_truncated', ['limit' => $limite, 'total' => count($erreurs)]) }}
                    </p>
                @endif
            @endif
        </section>
    @elseif($onglet === 'json')
        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="mb-3 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.scenario_manager.preview_json_hint') }}</p>
            {{-- Le document n'est ECRIT DANS LA PAGE que sur cet onglet : a
                 2 MiB autorises, le verser dans les huit autres couterait cher
                 pour rien. Il n'est pas « charge paresseusement » pour autant —
                 la colonne est lue par le route-binding quel que soit
                 l'onglet ; `<details>` ne fait que replier un contenu deja
                 transmis. --}}
            <details>
                <summary class="cursor-pointer text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('admin.scenario_manager.preview_show_json') }}</summary>
                <pre class="mt-3 max-h-[32rem] overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ $version->json_source }}</pre>
            </details>
        </section>
    @else
        @foreach($familles as $famille => $colonnes)
            @php
                $lignes = $preview->family($famille);
                $total = count($lignes);
                // `training.modules` se lit « modules » : le prefixe dit ou la
                // famille vit dans le document, pas comment on la nomme.
                $titre = \Illuminate\Support\Str::afterLast($famille, '.');
            @endphp

            <section class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <h2 class="border-b border-gray-100 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    {{ $titre }} <span class="ml-1 font-normal normal-case text-gray-400">({{ $total }})</span>
                </h2>

                @if($total === 0)
                    <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.scenario_manager.preview_empty_tab') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    @foreach($colonnes as $colonne)
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $colonne }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                {{-- Borne dure : un manifeste peut declarer des
                                     centaines d'objets par famille, et rendre
                                     tout d'un coup ferait une page illisible
                                     autant que lente. --}}
                                @foreach(array_slice($lignes, 0, $limite) as $ligne)
                                    <tr>
                                        @foreach($colonnes as $colonne)
                                            @php $valeur = $ligne->{$colonne} ?? null; @endphp
                                            <td class="px-3 py-2 align-top text-gray-700 dark:text-gray-300">
                                                {{ is_scalar($valeur) ? Str::limit((string) $valeur, 90, '…') : '—' }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if($total > $limite)
                        <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            {{ __('admin.scenario_manager.preview_truncated', ['limit' => $limite, 'total' => $total]) }}
                        </p>
                    @endif
                @endif
            </section>
        @endforeach
    @endif
</x-admin-layout>
