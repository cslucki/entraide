{{-- TASK-1790 — la fiche individuelle.

     `$resumeApercu`/`$contenuApercu` sont DEJA assainis
     (RssArticleImporter::contenuAssaini(), meme fabrique que l'ecriture) :
     ce sont les deux seuls endroits de ce fichier ou du HTML est rendu sans
     echappement, et c'est volontaire (CDC §7). Tout le reste du gabarit
     echappe normalement. Affiches SEPAREMENT (correctif post-merge) : l'un
     n'est plus le repli de l'autre, a la difference de l'ecriture qui n'en
     garde qu'un. --}}
<x-admin-layout :title="$rssSourceArticle->titre">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.title') }}</p>

        <a href="{{ route('admin.outils.import-rss.sources.show', $rssSourceArticle->rss_source_id) }}"
           class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            {{ __('admin.import_rss.fiche.retour') }}
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- 1. Le contenu lu depuis le flux — titre/date/auteur/resume/contenu/lien. --}}
        <div class="lg:col-span-2 rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $rssSourceArticle->titre }}</h1>
            <p class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                @if($rssSourceArticle->source_published_at)<span>{{ $rssSourceArticle->source_published_at->format('d/m/Y') }}</span>@endif
                {{-- « auteur si disponible » : rien ne s'affiche quand le flux n'en donne pas. --}}
                @if($rssSourceArticle->auteur)<span>{{ $rssSourceArticle->auteur }}</span>@endif
            </p>

            @if($rssSourceArticle->url)
                <a href="{{ $rssSourceArticle->url }}" target="_blank" rel="nofollow noopener"
                   class="mt-2 inline-flex items-center gap-1 text-sm text-indigo-600 underline hover:no-underline dark:text-indigo-400">
                    {{ __('admin.import_rss.fiche.lien_original') }}
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            @endif

            @if(! $resumeApercu && ! $contenuApercu)
                <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">{{ __('admin.import_rss.fiche.sans_contenu') }}</p>
            @endif

            {{-- Resume et contenu complet, SEPAREMENT quand les deux
                 existent — voir le commentaire en tete de fichier. --}}
            @if($resumeApercu)
                <h2 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.resume_label') }}</h2>
                <div class="prose prose-sm mt-1 max-w-none dark:prose-invert">
                    {!! $resumeApercu !!}
                </div>
            @endif

            @if($contenuApercu)
                <h2 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.contenu_label') }}</h2>
                <div class="prose prose-sm mt-1 max-w-none dark:prose-invert">
                    {!! $contenuApercu !!}
                </div>
            @endif
        </div>

        {{-- 2. Destination et statuts. --}}
        <div class="space-y-4">
            <form wire:submit.prevent="creerBrouillon" class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <label for="organizationId" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.fiche.organization_label') }}</label>
                <select id="organizationId" wire:model.live="organizationId"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">{{ __('admin.import_rss.fiche.organization_vide') }}</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </select>
                @error('organizationId')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror

                <label for="loopId" class="mt-4 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.import_rss.fiche.loop_label') }}</label>
                <select id="loopId" wire:model="loopId" wire:key="loop-select-{{ $organizationId }}"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:opacity-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        @if(! $organizationId) disabled @endif>
                    <option value="">{{ __('admin.import_rss.fiche.loop_aucune') }}</option>
                    {{-- Variable nommee `$boucle`, PAS `$loop` : Blade reserve
                         `$loop` pour sa propre metadonnee d'iteration et
                         l'ecrase a chaque tour — mesure directement ici
                         (`Undefined property: stdClass::$id`). --}}
                    @foreach($loops as $boucle)
                        <option value="{{ $boucle->id }}">{{ $boucle->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.loop_aide') }}</p>

                <div class="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    @if(! $organizationId)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.choisir_organization_pour_creer') }}</p>
                    @elseif($peutCreer)
                        <button type="submit"
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                            {{ __('admin.import_rss.fiche.creer_brouillon') }}
                        </button>
                    @else
                        {{-- Deja importe pour l'Organization choisie : jamais le
                             bouton, meme si le navigateur etait manipule — la
                             verification serveur dans creerBrouillon() refuse
                             de toute facon. --}}
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.deja_importe_pour_cette_organization') }}</p>
                    @endif
                </div>
            </form>

            @if($statuts->isNotEmpty())
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.import_rss.fiche.deja_importe_titre') }}</p>
                    <ul class="mt-2 space-y-2">
                        @foreach($statuts as $orgId => $info)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="truncate text-gray-700 dark:text-gray-300">{{ $organisationsConcernees[$orgId]->name ?? $orgId }}</span>
                                @if($info['trashed'])
                                    {{-- Supprime : jamais de lien, la route
                                         admin.blog.edit n'est pas withTrashed()
                                         et rendrait un 404 (meme regle que
                                         l'ecran en lot, TASK-1789). --}}
                                    <span class="shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.import_rss.supprime') }}</span>
                                @else
                                    <a href="{{ route('admin.blog.edit', ['post' => $info['blog_post_id']]) }}"
                                       class="shrink-0 text-xs text-indigo-600 underline hover:no-underline dark:text-indigo-400">
                                        {{ $info['statut'] === 'publie' ? __('admin.import_rss.fiche.statut_publie') : __('admin.import_rss.fiche.statut_brouillon') }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
