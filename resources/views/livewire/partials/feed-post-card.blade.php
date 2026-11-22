@php
    $myReaction = $post->reactions->firstWhere('user_id', auth()->id())?->reaction_type;
    $reactionCounts = $post->reactions->groupBy('reaction_type')->map->count();
    $totalReactions = $reactionCounts->sum();
    $commentCount = $post->comments->count();
    // TASK-1788 correctif 09 — l'apercu « dernier commentaire » a disparu, et
    // la variable avec. Meme retrait que sur la fiche d'article de blog : le
    // fil entier s'ouvre au clic sur l'icone, un extrait au-dessus ne faisait
    // que le repeter — c'est ce qui donnait l'impression de voir son propre
    // commentaire DEUX FOIS apres l'avoir poste. `$commentCount` sert encore
    // au compteur de la barre.
    $preview = $post->url_preview;
    $authorName = $post->user?->publicDisplayName() ?? __('feed.member_fallback');

    // TASK-1752 (design A, choix Cyril) — un evenement n'est pas une annonce :
    // il n'a ni titre, ni image, ni paragraphe. Sa carte est une LIGNE.
    $estEvenement = \App\Support\Feed\FeedEventCatalogue::connait($post->type);

    // TASK-1812 correctif 07 (demande Cyril) — l'avatar de l'auteur sort de la
    // carte et prend sa gouttiere a gauche, « comme les avatars dans le chat » :
    // meme principe que la gouttiere detachee du ChatLoop (TASK-1772,
    // `detachedAvatar` de `x-conversation.message-bubble`). Calcule ICI, une
    // fois, pour servir aux DEUX presentations (evenement et annonce) — avant,
    // seule la ligne d'evenement le savait.
    //
    // `null` pour un compte desactive ou hors Organization : meme discipline
    // que le reste du Flux, un avatar qui ne mene nulle part reste une image,
    // jamais un lien mort.
    $_slugOrganization = currentOrganization()?->slug;
    $_lienProfil = \App\Support\Feed\FeedEventRenderer::lienProfil($post->user, $_slugOrganization);

    // TASK-1879 (design, demande Cyril) — un evenement NE dans une Boucle
    // (sondage, veille, Roadmap, depot, article, evenement de Boucle) porte un
    // bandeau « lieu » en tete de carte. Pas pour l'adhesion ni la creation :
    // leur apercu EST deja la Boucle.
    $_boucle = $estEvenement
        && ! in_array($post->type, [\App\Support\Feed\FeedEventCatalogue::ADHESION_BOUCLE, \App\Support\Feed\FeedEventCatalogue::BOUCLE_CREEE], true)
        ? $post->boucle
        : null;
    $_lienBoucle = $_boucle && $_slugOrganization
        ? route('organization.loops.show', ['organization' => $_slugOrganization, 'loop' => $_boucle->slug ?: $_boucle->getKey()])
        : null;
@endphp

<div class="flex items-start gap-3">
    {{-- TASK-1812 correctif 07 — LA GOUTTIERE D'AVATAR, detachee de la carte.
         `size="md" variant="solid"` : la MEME mise en forme que les avatars
         des commentaires du Flux juste plus bas dans cette carte (TASK-1788
         correctif 09, pastille pleine façon ChatLoop) — demande de Cyril en
         recette, la pastille par defaut (`sm`, translucide) se voyait a peine
         a cote de la carte. --}}
    @if($_lienProfil)
        <a href="{{ $_lienProfil }}"
           data-feed-post-avatar="{{ $post->id }}"
           aria-label="{{ $authorName }}"
           class="mt-0.5 shrink-0 rounded-full transition hover:ring-2 hover:ring-indigo-300 dark:hover:ring-indigo-600">
            <x-user-avatar :user="$post->user" size="md" variant="solid" />
        </a>
    @else
        <x-user-avatar :user="$post->user" size="md" variant="solid" class="mt-0.5 shrink-0" />
    @endif

    <article id="feed-post-{{ $post->id }}" class="min-w-0 flex-1 overflow-hidden rounded-3xl border bg-white shadow-sm dark:bg-gray-900 {{ $_boucle ? 'border-indigo-200 dark:border-indigo-900/70' : 'border-gray-200 dark:border-gray-700' }}" x-data="{ showComments: false, showReactions: false }">
    @if($_boucle)
        {{-- Le bandeau « lieu » : la porte de la Boucle, au-dessus de ce qui s'y est passe. --}}
        <div data-feed-loop-band="{{ $_boucle->id }}"
             class="flex items-center gap-3 border-b border-indigo-100 bg-indigo-50/80 px-4 py-2.5 sm:px-5 dark:border-indigo-900/60 dark:bg-indigo-950/40">
            @if($_boucle->cover_image_url)
                <img src="{{ $_boucle->cover_image_url }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-indigo-900">
            @else
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white ring-2 ring-white dark:bg-indigo-500 dark:ring-indigo-900" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ config('navigation_icons.loops') }}"/></svg>
                </span>
            @endif
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-indigo-950 dark:text-indigo-50">{{ $_boucle->name }}</span>
                <span class="flex items-center gap-1 text-[11px] text-indigo-700/80 dark:text-indigo-300/80">
                    @if($_boucle->isPrivate())
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                        {{ __('loops.visibility_private') }}
                    @else
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/></svg>
                        {{ __('loops.visibility_public') }}
                    @endif
                </span>
            </span>
            @if($_lienBoucle)
                <a href="{{ $_lienBoucle }}"
                   class="shrink-0 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 shadow-sm ring-1 ring-indigo-200 transition hover:bg-indigo-600 hover:text-white hover:ring-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:bg-indigo-900/60 dark:text-indigo-100 dark:ring-indigo-800 dark:hover:bg-indigo-500">
                    {{ __('feed.open_loop') }}
                </a>
            @endif
        </div>
    @endif
    @if($post->image_path)
        <img src="{{ $post->imageUrl() }}" alt="" class="h-56 w-full object-cover sm:h-72">
    @endif

    <div class="{{ $estEvenement ? 'space-y-3 px-4 py-3 sm:px-5' : 'space-y-4 p-4 sm:p-5' }}">
        @unless($estEvenement)
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ \App\Support\Feed\FeedEventCatalogue::connait($post->type) ? __('feed.events.badge') : __('feed.post_badge') }}</span>
                    @if($post->isPinned())
                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-200">{{ __('feed.pinned_badge') }}</span>
                    @endif
                    @if($post->loops->isNotEmpty())
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ trans_choice('feed.loop_count', $post->loops->count(), ['count' => $post->loops->count()]) }}
                        </span>
                    @endif
                </div>
                @if($post->title)
                    <h2 class="mt-3 text-lg font-bold text-gray-950 dark:text-gray-50">{{ $post->title }}</h2>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $authorName }} · {{ $post->published_at?->isoFormat('lll') ?? $post->created_at->isoFormat('lll') }}
                </p>
            </div>
        </header>
        @endunless

        {{-- TASK-1752 — DEUX rendus, et la separation n'est pas cosmetique.

            Un evenement automatique ne passe JAMAIS par `markdown()`. Le
            filtre est sur : `html_input => escape`, donc pas de faille HTML.
            Mais la syntaxe Markdown reste active, et un nom contenant
            `[texte](url)` produirait un lien. `{{ }}` echappe, point.

            Le libelle est CALCULE : la langue et le nom sont resolus
            maintenant, pas au moment ou la ligne a ete ecrite. --}}
        @if($estEvenement)
            {{-- La ligne : phrase ou le NOM et l'OBJET sont des liens, date
                courte a droite. L'avatar n'y est plus : TASK-1812 correctif 07
                l'a detache dans la gouttiere de gauche, calcule en tete de ce
                fichier et commun aux deux presentations.

                `ligneHtml()` rend du HTML dont TOUTES les parties dynamiques
                sont echappees par `e()` — c'est son contrat, ecrit dans son
                docblock. Jamais `markdown()` ici : la syntaxe resterait
                active et un nom contenant `[texte](url)` produirait un lien. --}}
            <div class="flex items-center gap-3" data-feed-event="{{ $post->type }}">
                <p class="min-w-0 flex-1 text-sm leading-snug text-gray-700 dark:text-gray-200">
                    {!! \App\Support\Feed\FeedEventRenderer::ligneHtml($post, $_slugOrganization) !!}
                </p>

                <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">
                    {{ ($post->published_at ?? $post->created_at)->isoFormat('ll') }}
                </span>
            </div>

            {{-- La PIECE JOINTE graphique (demande Cyril) : extrait de
                presentation + photo pour une personne, couverture pour une
                Boucle, extrait du texte pour une demande ou une proposition.
                Resolue a l'affichage comme le libelle — jamais gelee.
                Plus d'indentation `ms-10` : elle compensait la largeur de
                l'avatar qui vivait ICI, dans la carte — il est maintenant hors
                d'elle, dans sa propre gouttiere (TASK-1812 correctif 07). --}}
            @php($_apercu = \App\Support\Feed\FeedEventRenderer::apercu($post, $_slugOrganization))
            @if($_apercu !== null)
                @php($_balise = $_apercu['url'] ? 'a' : 'div')
                <{{ $_balise }} @if($_apercu['url']) href="{{ $_apercu['url'] }}" @endif
                    class="flex items-center gap-3 rounded-2xl border border-gray-100 bg-gray-50 p-3 transition dark:border-gray-800 dark:bg-gray-800/50 {{ $_apercu['url'] ? 'hover:bg-gray-100 dark:hover:bg-gray-800' : '' }}"
                    data-feed-event-apercu>
                    @if($_apercu['image'])
                        <img src="{{ $_apercu['image'] }}" alt=""
                             class="{{ $_apercu['ronde'] ? 'h-14 w-14 rounded-full' : 'h-14 w-20 rounded-xl' }} shrink-0 object-cover">
                    @elseif(! empty($_apercu['date']))
                        {{-- TASK-1879 (demande Cyril) — la tuile date de la Card Evenements. --}}
                        <span class="flex h-16 w-14 shrink-0 flex-col items-center justify-center rounded-xl text-center text-white {{ $_apercu['date']['annule']
                                ? 'bg-gradient-to-br from-gray-400 to-gray-500 dark:from-gray-600 dark:to-gray-700'
                                : 'bg-gradient-to-br from-indigo-600 to-indigo-700 dark:from-indigo-500 dark:to-indigo-600' }}" data-feed-event-date>
                            <span class="text-[9px] font-bold uppercase leading-none opacity-80">{{ $_apercu['date']['jour'] }}</span>
                            <span class="font-mono text-xl font-bold leading-tight tabular-nums">{{ $_apercu['date']['quantieme'] }}</span>
                            <span class="text-[9px] font-semibold leading-none opacity-90">{{ $_apercu['date']['mois'] }}</span>
                            <span class="mt-1 rounded-full bg-white/20 px-1 font-mono text-[9px] leading-tight tabular-nums">{{ $_apercu['date']['heure'] }}</span>
                        </span>
                    @elseif(! empty($_apercu['icone']))
                        {{-- TASK-1809 (correctif Cyril) — la MEME icone que le
                             rail gauche (« Entraide »), jamais une seconde
                             icone inventee pour le meme concept. --}}
                        {{-- TASK-1879 — `teinte` optionnelle (la Veille est sky) ;
                             par defaut, l'indigo de l'Entraide. --}}
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl {{ match ($_apercu['teinte'] ?? null) {
                            'sky' => 'bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300',
                            'violet' => 'bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300',
                            'rose' => 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-300',
                            'emerald' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300',
                            'amber' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300',
                            'teal' => 'bg-teal-100 text-teal-600 dark:bg-teal-900/40 dark:text-teal-300',
                            default => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300',
                        } }}">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $_apercu['icone'] }}" />
                            </svg>
                        </span>
                    @endif
                    <span class="min-w-0 flex-1">
                        @if(! empty($_apercu['categorie']))
                            <x-veille.categorie :category="$_apercu['categorie']" class="mb-0.5 text-[11px]" />
                        @endif
                        @if($_apercu['titre'])
                            <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $_apercu['titre'] }}</span>
                        @endif
                        @if($_apercu['texte'])
                            <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 line-clamp-2 dark:text-gray-400">{{ $_apercu['texte'] }}</span>
                        @endif
                    </span>
                </{{ $_balise }}>
            @endif
        @elseif(filled($post->content))
            <div class="prose prose-sm max-w-none text-gray-800 dark:prose-invert dark:text-gray-100">
                {!! markdown($post->content) !!}
            </div>
        @endif

        @if(is_array($preview))
            <a href="{{ $preview['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer" class="flex gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-3 transition hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800/70 dark:hover:bg-gray-800">
                @if(! empty($preview['image']))
                    <img src="{{ $preview['image'] }}" alt="" class="h-16 w-16 rounded-xl object-cover">
                @endif
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $preview['domain'] ?? parse_url($preview['url'] ?? '', PHP_URL_HOST) }}</span>
                    <span class="mt-1 block line-clamp-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $preview['title'] ?? $preview['url'] ?? __('feed.shared_link') }}</span>
                    @if(! empty($preview['description']))
                        <span class="mt-1 block line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $preview['description'] }}</span>
                    @endif
                </span>
            </a>
        @endif

        {{-- TASK-1788 correctif 09 — des cibles TACTILES, pas des liens de souris.

             Les deux commandes faisaient une vingtaine de pixels de haut : au
             doigt, sur une PWA, on les rate. `min-h-[44px]` est la cible que le
             reste du produit respecte deja (etat vide des Boucles), et
             `-my-2` reprend la hauteur ajoutee pour que la rangee ne grossisse
             pas visuellement. --}}
        <div class="flex items-center gap-4 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
            <button type="button" @click="showComments = !showComments" class="-my-2 inline-flex min-h-[44px] items-center gap-1.5 py-2 text-gray-500 hover:text-indigo-600 transition dark:text-gray-400 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                @if($commentCount > 0)
                    <span>{{ $commentCount }}</span>
                @else
                    <span>{{ __('feed.comment_cta') }}</span>
                @endif
            </button>

            {{-- TASK-1788 correctif 09 (point 9) — la commande montre MA
                 reaction, pas un pouce generique.

                 Facon Facebook : des que j'ai reagi, c'est mon emoji qui
                 s'affiche et le libelle passe a l'accent — on voit d'un coup
                 d'oeil qu'on a deja reagi, et avec quoi. Sans reaction de ma
                 part, le pouce reste l'invitation.

                 `$myReaction` est deja calcule en haut de cette carte, sur la
                 collection `reactions` chargee avec l'annonce : aucune requete
                 de plus. --}}
            <button type="button" @click="showReactions = !showReactions"
                    data-feed-reaction-trigger="{{ $myReaction ?? '' }}"
                    class="-my-2 inline-flex min-h-[44px] items-center gap-1.5 py-2 transition {{ $myReaction
                        ? 'font-semibold text-indigo-600 dark:text-indigo-400'
                        : 'text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400' }}">
                <span class="text-base">{{ $myReaction ? ($emojiMap[$myReaction] ?? '👍') : '👍' }}</span>
                @if($totalReactions > 0)
                    <span>{{ $totalReactions }}</span>
                @endif
            </button>
        </div>

        {{-- TASK-1788 correctif 09 (point 4) — la disposition d'un fil de
             commentaires, telle que tout le monde la connait.

             Trois choses, demandees sur l'exemple de Facebook :

             1. **un avatar a GAUCHE** de chaque commentaire — c'est le visage
                qu'on cherche en parcourant, pas le nom ;
             2. **la zone pour ecrire APRES les commentaires** — on lit, puis on
                repond. Elle etait au-dessus : on ecrivait avant d'avoir lu ;
             3. **l'ordre chronologique**. La relation `comments()` est
                `latest()` — le plus recent d'abord, ce qui convenait quand la
                carte n'en montrait qu'un. Avec le fil complet et le champ en
                bas, il faut lire du plus ancien au plus recent, sinon la
                reponse qu'on vient d'ecrire apparait tout en haut, loin du
                champ. Le tri est fait A L'AFFICHAGE (`sortBy`), sur la
                collection deja chargee : la relation n'est pas touchee, et les
                autres surfaces qui s'en servent gardent leur ordre. C'est
                d'ailleurs ce que faisait la fenetre qu'on vient de retirer. --}}
        <div x-show="showComments" x-cloak class="space-y-3">
            @if($commentCount > 0)
                <div class="space-y-3">
                    @foreach($post->comments->sortBy('created_at') as $comment)
                        {{-- TASK-1788 correctif 09 (points 5 et 6) — l'avatar
                             MENE AU PROFIL, et il est mis en forme comme dans
                             les ChatLoops.

                             Le lien passe par `FeedEventRenderer::lienProfil()`,
                             deja employe par la ligne d'evenement juste
                             au-dessus : une seule autorite sur « ou mene le
                             visage de quelqu'un ». Elle rend `null` pour une
                             personne desactivee ou hors Organization — et dans
                             ce cas la pastille reste une IMAGE. Meme discipline
                             que la bulle de ChatLoop, dont le commentaire le
                             dit : « un bouton qui n'ouvre rien est pire qu'une
                             image, il promet un geste qui ne vient pas. »

                             La mise en forme est celle de la gouttiere du
                             ChatLoop : pastille ronde detachee, alignee en
                             HAUT (en face du nom, jamais de la derniere ligne
                             d'un long commentaire), et un anneau d'accent au
                             survol et au focus clavier. --}}
                        @php($_lienCommentateur = \App\Support\Feed\FeedEventRenderer::lienProfil($comment->user, currentOrganization()?->slug))
                        <div class="flex items-start gap-2" data-feed-comment>
                            @if($_lienCommentateur)
                                <a href="{{ $_lienCommentateur }}"
                                   data-feed-comment-avatar="{{ $comment->user?->id }}"
                                   aria-label="{{ $comment->user?->publicDisplayName() ?? __('feed.member_fallback') }}"
                                   class="mt-0.5 shrink-0 rounded-full ring-0 transition hover:ring-2 hover:ring-[var(--bp-accent)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-accent)]">
                                    <x-user-avatar :user="$comment->user" size="md" variant="solid" />
                                </a>
                            @else
                                <x-user-avatar :user="$comment->user" size="md" variant="solid" class="mt-0.5 shrink-0" />
                            @endif
                            <div class="min-w-0 flex-1 rounded-2xl bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800/60">
                                <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $comment->user?->publicDisplayName() ?? __('feed.member_fallback') }}</p>
                                <p class="mt-0.5 whitespace-pre-line break-words text-gray-600 dark:text-gray-300">{{ $comment->content }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Le champ, avec le visage de qui ecrit : la meme grammaire que
                 les commentaires au-dessus, pour que la zone se lise comme la
                 suite du fil et non comme un formulaire a part. --}}
            <form wire:submit="addComment('{{ $post->id }}')" class="flex items-start gap-2" data-feed-comment-form>
                {{-- Meme taille que les commentaires au-dessus : sans cela la
                     gouttiere ne serait pas droite. Pas de lien ici — c'est son
                     propre visage, et le champ n'est pas un fil. --}}
                <x-user-avatar :user="auth()->user()" size="md" variant="solid" class="mt-0.5 shrink-0" />
                <div class="min-w-0 flex-1 space-y-2">
                    <textarea wire:model="commentForms.{{ $post->id }}" rows="2" class="w-full rounded-2xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('feed.comment_placeholder') }}"></textarea>
                    @error('commentForms.'.$post->id) <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    <button type="submit" class="inline-flex min-h-[44px] items-center rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">{{ __('feed.comment_submit') }}</button>
                </div>
            </form>
        </div>

        {{-- TASK-1788 correctif 09 (point 7) — la palette se REFERME, et elle
             tient moins de place.

             Elle restait ouverte apres le choix : on avait reagi, et la rangee
             de six pastilles continuait de pousser la carte vers le bas sans
             plus rien proposer. `showReactions = false` la referme — pose a
             cote du `wire:click`, les deux partent au meme clic.

             La fermeture vaut aussi quand on RETIRE sa reaction : le geste est
             fait dans les deux cas, et une palette qui ne se ferme que dans un
             sens serait plus deroutante qu'utile.

             36 px et non 44 : six pastilles a 44 px debordent sur deux rangees
             sur un telephone, et c'est justement ce qu'on cherche a eviter ici.
             36 px reste une cible franche — c'est celle des suggestions du
             ChatLoop (`min-h-[36px]`). Les commandes PRINCIPALES de la carte,
             elles, gardent leurs 44 px. --}}
        <div x-show="showReactions" x-cloak class="flex flex-wrap items-center gap-1.5" data-feed-reactions>
            @foreach(array_slice($emojiMap, 0, 6, true) as $type => $emoji)
                {{-- TASK-1788 correctif 09 (point 8) — UN seul gestionnaire, et il
                     est ORDONNE.

                     La premiere version posait `wire:click` ET `@click` sur le
                     meme bouton : deux ecouteurs independants sur un meme clic,
                     dont l'un cache l'element (`x-show`) pendant que l'autre
                     doit encore composer sa requete. Changer de reaction ou
                     annuler la sienne ne produisait plus rien a l'ecran.

                     ⚠️ Le serveur, lui, etait JUSTE — mesure avant de toucher
                     quoi que ce soit : meme type -> la ligne est supprimee ;
                     type different -> elle est modifiee ; aucune -> elle est
                     creee. Il ne fallait donc rien corriger de ce cote.

                     `$wire.toggleReaction(...)` rend une promesse : la palette
                     ne se referme qu'une fois la reaction ENREGISTREE. Si la
                     requete echoue, elle reste ouverte — ce qui est la bonne
                     reponse : le geste n'a pas eu lieu. --}}
                <button type="button"
                        data-feed-reaction="{{ $type }}"
                        @click="$wire.toggleReaction('{{ $post->id }}', '{{ $type }}').then(() => showReactions = false)"
                        class="inline-flex min-h-[36px] min-w-[36px] items-center justify-center rounded-full border px-2 py-1 text-sm transition {{ $myReaction === $type ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}" aria-label="{{ __('feed.react', ['type' => $type]) }}">
                    <span>{{ $emoji }}</span>
                    @if(($reactionCounts[$type] ?? 0) > 0)
                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $reactionCounts[$type] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

    </div>
</article>
</div>
