{{-- TASK-1785 — l'ecran d'import RSS.

     Sobre et sans nouveau framework, comme demande : Tailwind et Alpine, tous
     deux deja en place. Le seul comportement scripte est le selecteur de
     Boucle, qui doit se recharger quand l'Organization change — le CDC exige
     que seules les Boucles de l'Organization choisie soient proposees, et que
     le changement d'Organization REINITIALISE une Boucle devenue incompatible.

     La liste des Boucles est demandee au serveur a ce moment-la, et n'est
     jamais envoyee d'avance : expedier toutes les Boucles de toutes les
     Organizations au navigateur serait une fuite inter-tenant, meme masquee a
     l'ecran. La validation refait de toute facon le controle cote serveur. --}}
<x-admin-layout :title="__('admin.import_rss.title')">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.subtitle') }}</p>

        {{-- Navigation reciproque, cote outil (CDC §6). --}}
        <a href="{{ route('admin.blog') }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
            {{ __('admin.import_rss.go_to_blog') }}
        </a>
    </div>

    @if(session('rss_erreur'))
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
            {{ __('admin.import_rss.erreurs.'.session('rss_erreur')) }}
        </div>
    @endif

    @if(session('rss_charge') !== null)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
            {{ __('admin.import_rss.charge', ['nombre' => session('rss_charge')]) }}
        </div>
    @endif

    {{-- La confirmation detaillee apres import (CDC §1.10) : crees, doublons
         ignores, erreurs, et un lien par article. --}}
    @if($resultat = session('rss_resultat'))
        <div class="mb-6 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm dark:border-indigo-800 dark:bg-indigo-900/20">
            <p class="font-semibold text-indigo-900 dark:text-indigo-200">
                {{ __('admin.import_rss.resultat', [
                    'crees' => count($resultat['crees']),
                    'doublons' => count($resultat['doublons']),
                    'erreurs' => count($resultat['erreurs']),
                ]) }}
            </p>

            @foreach(['crees', 'doublons'] as $categorie)
                @if($resultat[$categorie] !== [])
                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">
                        {{ __('admin.import_rss.'.$categorie) }}
                    </p>
                    <ul class="mt-1 space-y-1">
                        @foreach($resultat[$categorie] as $ligne)
                            <li class="flex flex-wrap items-center gap-2 text-gray-700 dark:text-gray-300">
                                <span class="truncate">{{ $ligne['titre'] }}</span>
                                {{-- « Modifier » dans le SuperAdmin : la seule
                                     destination toujours valable, un brouillon
                                     n'ayant pas d'URL publique. --}}
                                <a href="{{ route('admin.blog.edit', ['post' => $ligne['id']]) }}"
                                   class="text-indigo-600 underline hover:no-underline dark:text-indigo-400">{{ __('admin.import_rss.modifier') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach

            @if($resultat['erreurs'] !== [])
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">
                    {{ __('admin.import_rss.erreurs_titre') }}
                </p>
                <ul class="mt-1 space-y-1">
                    @foreach($resultat['erreurs'] as $ligne)
                        <li class="text-red-700 dark:text-red-300">
                            {{ $ligne['titre'] }} — {{ __('admin.import_rss.erreurs.'.$ligne['raison']) }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- 1. L'URL du flux, son chargement et son rafraichissement. --}}
    <form method="POST" action="{{ route('admin.outils.import-rss.charger') }}"
          class="mb-6 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        @csrf
        <label for="url" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.url_label') }}</label>
        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
            <input type="url" name="url" id="url" required list="flux-connus"
                   value="{{ old('url', $url) }}"
                   placeholder="https://exemple.com/feed/"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            {{-- Les URL deja consultees, memorisees pour cette session. --}}
            <datalist id="flux-connus">
                @foreach($historique as $connu)
                    <option value="{{ $connu }}"></option>
                @endforeach
            </datalist>
            <button type="submit"
                    class="shrink-0 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                {{ $articles === [] ? __('admin.import_rss.charger') : __('admin.import_rss.rafraichir') }}
            </button>
        </div>
        @error('url')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </form>

    @if($articles !== [])
        {{-- 2. Prévisualisation, sélection, destination, import. --}}
        <form method="POST" action="{{ route('admin.outils.import-rss.importer') }}"
              x-data="{ org: '', boucles: [], boucle: '', publier: false, droits: false,
                        async chargerBoucles() {
                            this.boucle = '';
                            this.boucles = [];
                            if (! this.org) return;
                            const r = await fetch(`{{ url('admin/outils/import-rss/boucles') }}/${this.org}`, { headers: { 'Accept': 'application/json' } });
                            if (r.ok) this.boucles = await r.json();
                        } }"
              class="space-y-4">
            @csrf

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="organization_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.organization_label') }}</label>
                        <select name="organization_id" id="organization_id" required
                                x-model="org" @change="chargerBoucles()"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">{{ __('admin.import_rss.organization_vide') }}</option>
                            @foreach($organizations as $organization)
                                <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="loop_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.loop_label') }}</label>
                        <select name="loop_id" id="loop_id" x-model="boucle" :disabled="! org"
                                class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">{{ __('admin.import_rss.loop_aucune') }}</option>
                            <template x-for="b in boucles" :key="b.id">
                                <option :value="b.id" x-text="b.name"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.loop_aide') }}</p>
                    </div>
                </div>

                {{-- La publication explicite, et sa confirmation de droits. Les
                     DEUX cases sont exigees cote serveur : l'une sans l'autre
                     reste un brouillon. --}}
                <div class="mt-4 space-y-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="publier" value="1" x-model="publier" class="mt-0.5 rounded border-gray-300 text-indigo-600">
                        <span>{{ __('admin.import_rss.publier') }}</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm" :class="publier ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-500'">
                        <input type="checkbox" name="droits_confirmes" value="1" x-model="droits" :disabled="! publier" class="mt-0.5 rounded border-gray-300 text-indigo-600">
                        <span>{{ __('admin.import_rss.droits') }}</span>
                    </label>
                    <p class="text-xs text-amber-700 dark:text-amber-400" x-show="publier && ! droits" x-cloak>
                        {{ __('admin.import_rss.droits_requis') }}
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{-- « Tout selectionner » porte sur les articles
                             CHARGES, pas sur l'historique du site (CDC §2). --}}
                        <input type="checkbox"
                               @change="$root.querySelectorAll('input[name=\'selection[]\']').forEach(c => c.checked = $event.target.checked)"
                               class="rounded border-gray-300 text-indigo-600">
                        {{ __('admin.import_rss.tout_selectionner', ['nombre' => count($articles)]) }}
                    </label>
                    <button type="submit"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                        {{ __('admin.import_rss.importer') }}
                    </button>
                </div>

                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($articles as $index => $article)
                        <li class="flex gap-3 px-4 py-3">
                            <input type="checkbox" name="selection[]" value="{{ $index }}"
                                   class="mt-1 shrink-0 rounded border-gray-300 text-indigo-600">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $article['titre'] }}</p>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                                    @if($article['publie_le'])<span>{{ $article['publie_le'] }}</span>@endif
                                    {{-- « auteur si disponible » : rien ne
                                         s'affiche quand le flux n'en donne pas,
                                         et rien n'est invente. --}}
                                    @if($article['auteur'])<span>{{ $article['auteur'] }}</span>@endif
                                    @if(! $article['contenu_html'])<span class="text-amber-600 dark:text-amber-400">{{ __('admin.import_rss.sans_contenu') }}</span>@endif
                                </p>
                                @if($article['resume'])
                                    <p class="mt-1 line-clamp-2 text-xs text-gray-600 dark:text-gray-400">{{ Str::limit(strip_tags($article['resume']), 200) }}</p>
                                @endif
                                @if($article['url'])
                                    <a href="{{ $article['url'] }}" target="_blank" rel="nofollow noopener"
                                       class="mt-1 inline-block truncate text-xs text-indigo-600 underline hover:no-underline dark:text-indigo-400">{{ $article['url'] }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </form>
    @endif
</x-admin-layout>
