@php use App\Support\Ai\NervousSystemMap; @endphp

<x-org-admin-layout :title="__('ai.map_title')" :organization="$organization">
    {{-- TASK-1481 — le PLAN de la gouvernance IA.

         Cette page ne gouverne RIEN. Elle repond a une question qui demandait
         jusqu'ici de connaitre une dizaine d'URL : « pourquoi l'IA de cette
         Organization se comporte-t-elle ainsi, et ou se regle chaque regle ? »

         Les autorites existaient toutes ; ce qui manquait etait de pouvoir les
         voir ensemble. Chaque ligne renvoie donc a l'ecran REEL qui la
         gouverne — et n'affiche un lien que si la route existe. --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.map_title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('ai.map_intro') }}</p>
    </div>

    <ol class="space-y-2" data-ai-map>
        @foreach($nodes as $node)
            @php
                $isLocked = $node['state'] === NervousSystemMap::STATE_LOCKED;
                $hideLink = $node['admin_requires_platform_admin'] && ! $isPlatformAdmin;
            @endphp
            <li class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                data-ai-map-node="{{ $node['key'] }}"
                data-ai-map-level="{{ $node['level'] }}"
                data-ai-map-state="{{ $node['state'] }}">

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.map_node_'.$node['key']) }}</p>
                        <p class="mt-0.5 max-w-2xl text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('ai.map_node_'.$node['key'].'_hint') }}</p>
                    </div>

                    {{-- Mesure a 390 px : avec `flex-shrink-0`, ce conteneur ne
                         retrecit jamais, donc son `flex-wrap` ne se declenche
                         jamais non plus — les badges sortaient du cadre de
                         70 px. Les deux classes se contredisaient. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Le NIVEAU : qui porte cette regle. --}}
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $node['level'] === NervousSystemMap::LEVEL_PLATFORM ? 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-300' : ($node['level'] === NervousSystemMap::LEVEL_ORGANIZATION ? 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200') }}">
                            {{ __('ai.map_level_'.$node['level']) }}
                        </span>

                        {{-- L'ETAT. Il n'est pas declare : il est derive de
                             l'existence d'une route d'ecriture dans cette zone
                             d'administration. « Verrouille » veut donc dire
                             « s'applique a vous, se gouverne ailleurs » — jamais
                             « impossible a changer ». --}}
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $isLocked ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' }}"
                              data-ai-map-state-label>
                            {{ __('ai.map_state_'.$node['state']) }}
                        </span>

                        @if($node['status'])
                            <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200" data-ai-map-status>{{ $node['status'] }}</span>
                        @endif
                    </div>
                </div>

                @if($node['admin_url'] && ! $hideLink)
                    <a href="{{ $node['admin_url'] }}"
                       class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-300"
                       data-ai-map-link="{{ $node['key'] }}">
                        {{ __('ai.map_open_admin') }}
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                @elseif($hideLink)
                    {{-- Le lien existe, mais pas pour cet acteur : on le DIT
                         plutot que de le proposer et de le laisser echouer. --}}
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500" data-ai-map-link-restricted="{{ $node['key'] }}">
                        {{ __('ai.map_platform_only') }}
                    </p>
                @endif
            </li>
        @endforeach
    </ol>

    <p class="mt-4 max-w-3xl text-xs text-gray-500 dark:text-gray-400" data-ai-map-footer>{{ __('ai.map_footer') }}</p>
</x-org-admin-layout>
