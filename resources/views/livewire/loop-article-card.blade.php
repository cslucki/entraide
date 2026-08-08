{{--
    L'atelier d'écriture de la Boucle.

    **Aucun formulaire ici.** L'éditeur, les audiences, les co-auteurs et les
    Séries existent depuis longtemps, avec leurs routes et leurs policies : cette
    Card les lit sous un autre angle et renvoie aux parcours existants.

    **Les brouillons des autres sont annoncés, jamais ouverts.** On dit qu'un
    texte existe, pas ce qu'il raconte : un collectif qui écrit a besoin de savoir
    que quelque chose est en cours, sans lire par-dessus l'épaule.
--}}
<div class="flex h-full flex-col gap-4" data-loop-article>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.article.no_access') }}
    </p>
@elseif (! $dossier)
    <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.article.empty_title') }}</h3>
        <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.article.no_dossier') }}</p>
    </div>
@else

    {{-- Mes brouillons : ce qu'on cherche en revenant écrire. Un brouillon
         commencé il y a trois semaines n'apparaît plus dans un classeur trié
         par date — c'est toute la raison d'être de cette Card. --}}
    <section>
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('loops.cards.article.my_drafts') }}
        </h3>

        @if ($myDrafts->isEmpty())
            <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.article.no_draft') }}</p>
        @else
            <ul class="mt-1.5 space-y-1.5">
                @foreach ($myDrafts as $brouillon)
                    <li class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $brouillon->title }}</span>
                        <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">
                            {{ __('loops.cards.article.updated', ['date' => $brouillon->updated_at?->translatedFormat('d/m/Y')]) }}
                        </span>
                        <a href="{{ $this->editUrl($brouillon) }}"
                           class="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-indigo-700">
                            {{ __('loops.cards.article.resume') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Ce qui est en cours ailleurs. Titre et auteur, rien de plus : aucun
         lien, aucun extrait. --}}
    @if ($othersDrafts->isNotEmpty())
        <section>
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('loops.cards.article.others_drafts') }}
            </h3>
            <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('loops.cards.article.others_drafts_hint') }}</p>

            <ul class="mt-1.5 space-y-1">
                @foreach ($othersDrafts as $ailleurs)
                    <li class="flex items-baseline gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="rounded-full bg-gray-200 px-1.5 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            {{ __('loops.cards.article.draft_badge') }}
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ $ailleurs->title }}</span>
                        @if ($ailleurs->user)
                            <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">
                                {{ __('loops.cards.article.by', ['name' => $ailleurs->user->publicDisplayName()]) }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Ce que la Boucle a publié. --}}
    <section>
        <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('loops.cards.article.published') }}
        </h3>

        @if ($published->isEmpty())
            <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.article.no_published') }}</p>
        @else
            <ul class="mt-1.5 space-y-1.5">
                @foreach ($published as $article)
                    <li class="rounded-xl border border-gray-200 bg-white px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center gap-2">
                            <a href="{{ $this->readUrl($article) }}"
                               class="min-w-0 flex-1 truncate text-sm font-medium text-indigo-700 underline hover:text-indigo-800 dark:text-indigo-300">
                                {{ $article->title }}
                            </a>
                        </div>
                        <p class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                            @if ($article->user)
                                {{ __('loops.cards.article.by', ['name' => $article->user->publicDisplayName()]) }} ·
                            @endif
                            {{ __('loops.cards.article.audience_label', ['audience' => $article->audience]) }}
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Les Séries, quand il y en a. Pas de gestion ici : la Card Dossiers et
         l'onglet Séries s'en chargent. --}}
    @if ($series->isNotEmpty())
        <section>
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('loops.cards.article.series') }}
            </h3>
            <ul class="mt-1.5 space-y-1">
                @foreach ($series as $serie)
                    <li class="flex items-baseline gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="min-w-0 flex-1 truncate">{{ $serie->name ?? $serie->rootBlogPost?->title }}</span>
                        <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">
                            {{ __('loops.cards.article.series_count', ['count' => $serie->items_count]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Les invitations de co-écriture encore sans réponse. Ce sont les
         `blog_post_invitations` existantes : aucun second système. --}}
    @if ($pendingCoAuthors->isNotEmpty())
        <section>
            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('loops.cards.article.pending_co_authors') }}
            </h3>
            <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('loops.cards.article.pending_hint') }}</p>

            <ul class="mt-1.5 space-y-1">
                @foreach ($pendingCoAuthors as $invitation)
                    <li class="flex items-baseline gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="min-w-0 flex-1 truncate">{{ $invitation->blogPost?->title }}</span>
                        <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">
                            {{ $invitation->recipient_name ?: $invitation->recipient_email }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Vers le parcours qui existe. La Card ne refait pas l'éditeur : il a ses
         audiences, ses snapshots et ses policies. --}}
    @if ($canCompose && $this->dossierUrl($dossier))
        <a href="{{ $this->dossierUrl($dossier) }}"
           class="mt-auto block rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-center text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
            + {{ __('loops.cards.article.write') }}
        </a>
    @endif

@endif
</div>
