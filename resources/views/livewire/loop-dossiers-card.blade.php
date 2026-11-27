{{--
    La Card Dossiers.

    Une fenetre sur le Dossier racine de la Boucle : ce qui a bouge recemment, et
    un chemin vers le reste. Elle ne charge jamais le Dossier entier, et n'ecrit
    rien — les deux gestes d'ecriture renvoient aux ecrans qui les portent deja.
--}}
@php
    $tile = 'rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)]';
    $quiet = 'inline-flex items-center gap-1.5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-2.5 py-1.5 text-xs font-semibold text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]';
    $sectionTitle = 'mb-1.5 text-[10px] font-bold uppercase tracking-wide text-[var(--bp-muted)]';
@endphp

{{-- TASK-1697 (5) — la carte suit les depots des AUTRES.

     Signale par Cyril : quand X depose un fichier, l'ecran de Y reste figé
     jusqu'au rechargement manuel.

     `wire:poll` est l'idiome DEJA en place ici — `loop-chat` et
     `message-thread` l'emploient. L'application n'a aucune diffusion temps
     reel (`BROADCAST_CONNECTION=log`) : introduire un websocket pour une liste
     de fichiers aurait ajoute une infrastructure a exploiter pour un gain que
     le sondage obtient.

     **15 s, et non 3 s comme le chat.** Un depot de fichier n'est pas une
     conversation : on n'attend pas la ligne suivante, on veut seulement ne pas
     rester devant un ecran perime. Cinq fois moins de requetes pour une
     attente qui reste imperceptible a l'echelle du geste.

     `.visible` : la Card ne sonde que lorsqu'elle est a l'ecran. Elle vit dans
     un panneau lateral souvent replie ou hors du viewport — sonder pour
     personne serait du trafic pur. --}}
<div class="space-y-3" wire:poll.visible.15s>

    @if(! $dossier)
        {{-- Pas de Dossier racine, ou pas le droit de le lire. Les deux cas se
             disent de la meme facon : il n'y a rien a montrer ici. --}}
        <div class="{{ $tile }} p-5 text-center">
            <p class="text-sm text-[var(--bp-muted)]">{{ __('loops.cards.dossiers.empty_title') }}</p>
        </div>

    {{-- TASK-1828 — navigation contextuelle depuis ChatLoop : un clic sur
         l'apercu Article ou Fichier d'un message ouvre cette Card ET y
         pose l'identifiant cible (voir `openArticleDetailFromChat()` /
         `openFileDetailFromChat()`). Les deux panneaux ci-dessous
         REMPLACENT la liste normale tant qu'un detail est ouvert — jamais
         une double navigation, jamais une nouvelle fenetre. `closeDetail()`
         revient a la liste SANS fermer la Card elle-meme (qui reste geree
         par `closeCard()` dans `loops/show.blade.php`). --}}
    @elseif($detailArticle)
        <div data-marqueur="dossier-detail-article" class="{{ $tile }} space-y-4 p-4">
            <button type="button" wire:click="closeDetail" class="{{ $quiet }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                {{ __('loops.cards.dossiers.detail_back') }}
            </button>

            <div>
                <p class="text-base font-bold leading-snug text-[var(--bp-text)]">{{ $detailArticle->title }}</p>
                <p class="mt-1 text-xs text-[var(--bp-muted)]">
                    {{ $detailArticle->user?->publicDisplayName() ?? '—' }}
                    @if($detailArticle->published_at)
                        · {{ $detailArticle->published_at->translatedFormat('d F Y') }}
                    @endif
                </p>
            </div>

            <div class="max-h-[50vh] overflow-y-auto rounded-xl border border-[var(--bp-border)] bg-white p-3 text-gray-800 dark:bg-gray-900 dark:text-gray-200 prose prose-sm dark:prose-invert max-w-none [&_img[data-resized]]:!max-w-[70%] [&_pre]:bg-gray-100 [&_pre]:dark:bg-gray-800 [&_pre]:rounded-lg [&_pre]:p-3 [&_pre]:font-mono [&_pre]:text-xs [&_pre]:overflow-x-auto [&_code]:bg-gray-100 [&_code]:dark:bg-gray-800 [&_code]:rounded [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-xs [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_table]:max-w-full [&_table]:overflow-x-auto [&_table]:block">
                {{-- TASK-1906 — contenu refiltre au rendu. --}}
                {!! $detailArticle->contenuAffichable() !!}
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- TASK-1879 (recette Cyril) — revenir aux Articles de la Card. --}}
                <button type="button" data-action="dossier-back-to-articles"
                        x-on:click="$wire.closeDetail().then(() => $nextTick(() => document.querySelector('[data-marqueur=dossier-articles]')?.scrollIntoView({ block: 'start' })))"
                        class="{{ $quiet }}">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                    {{ __('loops.cards.dossiers.detail_back_to_articles') }}
                </button>
                @if($detailArticleUrl)
                    <a href="{{ $detailArticleUrl }}" class="{{ $quiet }}">
                        {{ __('loops.cards.dossiers.detail_read_full_article') }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                    </a>
                @endif
            </div>
        </div>

    @elseif($detailFile || $highlightedFiles->isNotEmpty())
        <div data-marqueur="dossier-detail-file" class="{{ $tile }} space-y-4 p-4">
            <button type="button" wire:click="closeDetail" class="{{ $quiet }}">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                {{ __('loops.cards.dossiers.detail_back') }}
            </button>

            @if(! $detailFile && $highlightedFiles->count() > 1)
                {{-- Depot multiple : une selection BORNEE a ce depot, pas la
                     liste entiere du Dossier. --}}
                <div>
                    <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.detail_select_file') }}</p>
                    <ul class="divide-y divide-[var(--bp-border)]">
                        @foreach($highlightedFiles as $hf)
                            <li wire:key="highlighted-{{ $hf->id }}">
                                <button type="button" wire:click="selectHighlightedFile('{{ $hf->id }}')"
                                        class="flex w-full items-center gap-2.5 rounded-xl px-1.5 py-2 text-left transition hover:bg-[var(--bp-surface)]">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[var(--bp-surface)] text-[var(--bp-muted)]">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $hf->display_name ?: $hf->original_name }}</span>
                                        <span class="block truncate text-[10px] text-[var(--bp-muted)]">{{ $hf->typeLisible() }} · {{ $hf->tailleLisible() }}</span>
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @elseif($detailFile)
                <div>
                    <p class="truncate text-sm font-bold text-[var(--bp-text)]">{{ $detailFile->display_name ?: $detailFile->original_name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                        {{ $detailFile->uploader?->publicDisplayName() ?? '—' }} · {{ $detailFile->typeLisible() }} · {{ $detailFile->tailleLisible() }}
                    </p>
                </div>

                <div class="overflow-hidden rounded-xl border border-[var(--bp-border)] bg-[var(--bp-surface)]">
                    @if($detailFile->isImage() && ($detailFileUrls['preview'] ?? null))
                        <img src="{{ $detailFileUrls['preview'] }}" alt="{{ $detailFile->display_name ?: $detailFile->original_name }}" class="max-h-[60vh] w-full object-contain">
                    @elseif($detailFile->mime_type === 'application/pdf' && ($detailFileUrls['preview'] ?? null))
                        <iframe src="{{ $detailFileUrls['preview'] }}" class="h-[60vh] w-full border-0" title="{{ $detailFile->display_name ?: $detailFile->original_name }}"></iframe>
                    @elseif(in_array($detailFile->mime_type, ['text/plain', 'text/markdown'], true) && ($detailFileUrls['preview'] ?? null))
                        <iframe src="{{ $detailFileUrls['preview'] }}" class="h-[50vh] w-full border-0 bg-white" title="{{ $detailFile->display_name ?: $detailFile->original_name }}"></iframe>
                    @else
                        <div class="p-6 text-center">
                            <p class="text-xs text-[var(--bp-muted)]">{{ __('loops.cards.dossiers.detail_preview_unavailable') }}</p>
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    {{-- TASK-1879 (recette Cyril) — revenir a la Card Dossiers. --}}
                    <button type="button" wire:click="closeDetail" data-action="dossier-back-to-dossiers" class="{{ $quiet }}">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                        {{ __('loops.cards.dossiers.detail_back_to_dossiers') }}
                    </button>
                    @if($highlightedFiles->count() > 1)
                        <button type="button" wire:click="backToSelection" class="{{ $quiet }}">
                            {{ __('loops.cards.dossiers.detail_back_to_selection') }}
                        </button>
                    @endif
                    @if($detailFileUrls['download'] ?? null)
                        <a href="{{ $detailFileUrls['download'] }}" class="{{ $quiet }}" data-marqueur="dossier-detail-download">
                            {{ __('loops.cards.dossiers.detail_download') }}
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        </a>
                    @endif
                </div>
            @endif
        </div>

    @else

        {{-- ── En-tete : le Dossier, son volume, son ouverture ────────────── --}}
        <div class="{{ $tile }} p-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-[var(--bp-text)]">{{ $dossier->name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                        {{ trans_choice('loops.cards.dossiers.item_count', $total, ['count' => $total]) }}
                    </p>
                </div>

                @if($urls)
                    <a href="{{ $urls['open'] }}" class="{{ $quiet }} shrink-0">
                        {{ __('loops.cards.dossiers.open') }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </a>
                @endif
            </div>

            {{-- Les deux gestes rapides, sans quitter la Boucle. « Ecrire un
                 article » pose un brouillon deja lie au Dossier racine et a la
                 Boucle puis ouvre l'editeur Blog existant ; « Deposer un
                 fichier » envoie vers l'endpoint d'upload existant du Dossier
                 et rafraichit la Card. Aucun second editeur, aucun second
                 stockage. --}}
            @if($urls && ($canCreateArticle || $canUploadFile))
                <div class="mt-3 border-t border-[var(--bp-border)] pt-3"
                     x-data="{ writing: false, uploading: false, uploadError: '', pourcent: 0, phase: 'envoi', mesurable: false,
                     {{-- TASK-1889 — UN depot pour les deux entrees (fichiers, galerie). --}}
                     async deposer(event) {
                         const fichiers = [...event.target.files].slice(0, 5);
                         event.target.value = '';
                         if (! fichiers.length) return;
                         this.uploading = true; this.uploadError = ''; this.pourcent = 0;
                         // La mire du telephone ecoute ces deux
                         // evenements : la Card n'a pas a la
                         // connaitre, elle annonce, c'est tout.
                         const annonce = (p, ph) => {
                             this.pourcent = p;
                             this.phase = ph || 'envoi';
                             if (this.phase === 'envoi' && p > 0 && p < 100) { this.mesurable = true; }
                             window.dispatchEvent(new CustomEvent('bp-depot-progression', {
                                 detail: { pourcent: p, phase: this.phase, nombre: fichiers.length, nom: fichiers[0]?.name ?? '' },
                             }));
                         };
                         this.mesurable = false;
                         annonce(0, 'envoi');
                         const r = await window.bpTeleverserFichiers(@js($urls['upload']), fichiers, annonce);
                         this.uploading = false;
                         window.dispatchEvent(new CustomEvent('bp-depot-fichier', {
                             detail: { ok: r.ok, nombre: fichiers.length, raison: r.raison },
                         }));
                         if (r.ok) { this.$wire.$refresh(); }
                         else { this.uploadError = r.raison || @js(__('loops.cards.dossiers.upload_failed')); }
                     } }">
                    <div class="flex flex-wrap gap-2">
                        @if($canCreateArticle)
                            <button type="button" @click="writing = ! writing; uploadError = ''"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--bp-primary)] px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/></svg>
                                {{ __('loops.cards.dossiers.create_article') }}
                            </button>
                        @endif
                        @if($canUploadFile)
                            <label class="{{ $quiet }} cursor-pointer" :class="uploading && 'pointer-events-none opacity-50'">
                                <input type="file" class="hidden" multiple
                                       {{-- TASK-1772 (recette Cyril, 06/11) — LA MIRE, ici aussi.
                                            « Sinon on a l'impression que rien ne se passe, sur desktop
                                            et smartphone. » Le depot passait par `fetch`, qui ne
                                            rapporte AUCUNE progression d'envoi : le seul retour etait
                                            le libelle du bouton qui changeait de mot. Il passe par
                                            `bpTeleverserFichiers` (XHR), partage avec le composeur de
                                            la Boucle — une seule facon de televerser dans le produit,
                                            et une seule a corriger. --}}
                                       @change="deposer($event)">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg>
                                {{-- TASK-1772 — le chiffre SEULEMENT quand il veut dire
                                     quelque chose. Sur une liaison rapide le navigateur
                                     n'emet aucune progression intermediaire (mesure :
                                     12 Mo = 0 % puis 100 %, rien entre) : un « 0 % »
                                     fige ferait croire que rien ne part. --}}
                                <span x-text="! uploading
                                    ? @js(__('loops.cards.dossiers.upload_file'))
                                    : (phase === 'traitement'
                                        ? @js(__('loops.composer_file_processing'))
                                        : (mesurable
                                            ? @js(__('dossiers.file_upload_progress')).replace(':percent', pourcent)
                                            : @js(__('loops.cards.dossiers.uploading'))))"></span>
                            </label>
                            {{-- TASK-1889 (Cyril, 23/11) — sur telephone, le choix de fichier
                                 sans `accept` propose Fichiers, Appareil photo, Camera, mais
                                 JAMAIS la galerie : retrouver ses dernieres photos etait
                                 laborieux. `image/*,video/*` SANS `capture` ouvre le selecteur
                                 de photos du systeme. Meme depot, meme mire. Inutile sur
                                 ordinateur, ou le bouton Fichier suffit. --}}
                            <label class="{{ $quiet }} cursor-pointer lg:hidden" :class="uploading && 'pointer-events-none opacity-50'" data-action="depot-galerie">
                                <input type="file" class="hidden" multiple accept="image/*,video/*" @change="deposer($event)">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Zm10.5-11.25h.008v.008h-.008V9.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                                {{ __('loops.cards.dossiers.upload_gallery') }}
                            </label>
                            {{-- La barre, a cote du bouton : le pourcentage dit
                                 « ca avance », la barre dit « de combien ». --}}
                            {{-- Barre PLEINE et pulsee quand rien n'est mesurable (ou
                                 quand le serveur travaille) ; barre qui avance quand le
                                 navigateur sait dire ou on en est. --}}
                            <div x-show="uploading" x-cloak data-depot-progression class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[var(--bp-border)]" role="progressbar" x-bind:aria-valuenow="mesurable ? pourcent : null" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-[var(--bp-primary)] transition-all duration-200"
                                     x-bind:class="(mesurable && phase !== 'traitement') ? '' : 'animate-pulse'"
                                     x-bind:style="'width: ' + ((mesurable && phase !== 'traitement') ? pourcent : 100) + '%'"></div>
                            </div>
                        @endif
                    </div>

                    @if($canCreateArticle)
                        {{-- Le titre d'abord : c'est la grammaire de tous les
                             parcours de creation du Blog, et elle evite les
                             brouillons sans nom. --}}
                        <form x-show="writing" x-cloak method="POST" action="{{ $urls['write'] }}" class="mt-2 flex flex-wrap gap-2">
                            @csrf
                            <input type="text" name="title" required maxlength="255"
                                   placeholder="{{ __('loops.cards.dossiers.article_title_placeholder') }}"
                                   class="min-w-0 flex-1 rounded-xl border-[var(--bp-border)] bg-[var(--bp-panel)] px-3 py-1.5 text-xs text-[var(--bp-text)]">
                            <button type="submit"
                                    class="inline-flex items-center rounded-xl bg-[var(--bp-primary)] px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90">
                                {{ __('loops.cards.dossiers.article_start') }}
                            </button>
                        </form>
                    @endif

                    <p x-show="uploadError" x-cloak x-text="uploadError" class="mt-2 text-xs text-red-500" role="alert"></p>
                </div>
            @elseif($readOnly)
                <p class="mt-3 border-t border-[var(--bp-border)] pt-3 text-xs text-[var(--bp-muted)]">
                    {{ __('loops.cards.dossiers.archived_read_only') }}
                </p>
            @endif
        </div>

        {{-- ── Rien encore ────────────────────────────────────────────────── --}}
        @if($articles->isEmpty() && $files->isEmpty() && $series->isEmpty())
            <div class="{{ $tile }} p-5 text-center">
                <p class="text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.cards.dossiers.empty_title') }}</p>
                <p class="mt-1 text-xs leading-5 text-[var(--bp-muted)]">{{ __('loops.cards.dossiers.empty_body') }}</p>
            </div>
        @endif

        {{-- ── Articles recents ───────────────────────────────────────────── --}}
        @if($articles->isNotEmpty())
            <div data-marqueur="dossier-articles" class="{{ $tile }} scroll-mt-3 p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.recent_articles') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($articles as $article)
                        <li wire:key="article-{{ $article->id }}" class="flex items-center gap-1.5">
                            {{-- L'adresse **dans l'Organization de la Boucle** : la route nue retombe
                                 sur l'Organization par défaut. --}}
                            <a href="{{ $articleUrls[$article->id] }}"
                               class="flex min-w-0 flex-1 items-center gap-2.5 rounded-xl px-1.5 py-2 transition hover:bg-[var(--bp-surface)]">
                                <x-user-avatar :user="$article->user" size="xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $article->title }}</span>
                                    <span class="block truncate text-[10px] leading-tight text-[var(--bp-muted)]">
                                        {{ $article->user?->publicDisplayName() ?? '—' }} · {{ $article->updated_at->diffForHumans() }}
                                    </span>
                                </span>
                                @if($article->status === 'draft')
                                    <span class="shrink-0 rounded-full bg-[var(--bp-surface)] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-[var(--bp-muted)]">
                                        {{ __('loops.cards.dossiers.draft') }}
                                    </span>
                                @endif
                            </a>
                            {{-- TASK-1884 #3 — a COTE du lien de lecture, jamais dedans
                                 (pas de lien dans un lien). --}}
                            @isset($modifyUrls[$article->id])
                                <a href="{{ $modifyUrls[$article->id] }}" data-dossier-card-modify
                                   class="shrink-0 rounded-lg border border-[var(--bp-border)] px-2 py-0.5 text-[11px] font-semibold text-[var(--bp-primary)] transition hover:bg-[var(--bp-surface)]">
                                    {{ __('blog.btn_edit') }}
                                </a>
                            @endisset
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Series ─────────────────────────────────────────────────────── --}}
        @if($series->isNotEmpty())
            <div class="{{ $tile }} p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.series') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($series as $serie)
                        {{-- La Serie s'ouvre la ou elle vit : l'onglet
                             « contenus » de son Dossier. Ce produit n'a pas de
                             route de Serie dediee, et en creer une aurait fait
                             une seconde page concurrente. --}}
                        <li wire:key="series-{{ $serie->id }}">
                            <a @if($urls) href="{{ $urls['articles'] }}" @endif
                               class="flex items-center gap-2.5 rounded-xl px-1.5 py-2 transition @if($urls) hover:bg-[var(--bp-surface)] @endif">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    {{-- Le titre vient de la racine. Une Serie dont
                                         la racine manque se lit quand meme, elle ne
                                         casse pas la Card. --}}
                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)]">
                                        {{ $serie->rootBlogPost?->title ?? __('loops.cards.dossiers.series_untitled') }}
                                    </span>
                                    <span class="block text-[10px] leading-tight text-[var(--bp-muted)]">
                                        {{ trans_choice('loops.cards.dossiers.series_items', $serie->items_count, ['count' => $serie->items_count]) }}
                                    </span>
                                </span>
                                @if($urls)
                                    <svg class="h-3.5 w-3.5 shrink-0 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Fichiers recents ───────────────────────────────────────────── --}}
        @if($files->isNotEmpty())
            <div class="{{ $tile }} p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.recent_files') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($files as $file)
                        <li wire:key="file-{{ $file->id }}" class="flex items-center gap-2.5 px-1.5 py-2">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[var(--bp-surface)] text-[var(--bp-muted)]">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/></svg>
                            </span>
                            {{-- TASK-1697 (4) — le NOM ouvre le fichier.

                                 Signale par Cyril : la liste nommait les fichiers
                                 sans permettre de les ouvrir ; il fallait passer
                                 par « Ouvrir » et traverser tout le Dossier pour
                                 consulter un document deja visible.

                                 L'URL vient du COMPOSANT : elle differe selon que
                                 le fichier est depose dans ce Dossier ou partage
                                 avec la Boucle (T1695), et `$loop` n'est pas le
                                 modele dans cette vue. Voir `fileUrls()`.

                                 Le repli en `<span>` n'est pas decoratif : si la
                                 route manque, un lien mort serait pire qu'un
                                 libelle inerte. --}}
                            @if($fileUrls[$file->id] ?? null)
                                <a href="{{ $fileUrls[$file->id] }}" class="min-w-0 flex-1" data-card-file-open>
                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)] hover:underline">{{ $file->display_name ?: $file->original_name }}</span>
                                    <span class="block truncate text-[10px] leading-tight text-[var(--bp-muted)]">
                                        {{ $file->uploader?->publicDisplayName() ?? '—' }} · {{ $file->created_at->diffForHumans() }}
                                    </span>
                                </a>
                            @else
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $file->display_name ?: $file->original_name }}</span>
                                    <span class="block truncate text-[10px] leading-tight text-[var(--bp-muted)]">
                                        {{ $file->uploader?->publicDisplayName() ?? '—' }} · {{ $file->created_at->diffForHumans() }}
                                    </span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

    @endif
</div>
