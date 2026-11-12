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
@endphp

<article id="feed-post-{{ $post->id }}" class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" x-data="{ showComments: false, showReactions: false }">
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
            {{-- La ligne : avatar cliquable, phrase ou le NOM et l'OBJET sont
                des liens, date courte a droite.

                `ligneHtml()` rend du HTML dont TOUTES les parties dynamiques
                sont echappees par `e()` — c'est son contrat, ecrit dans son
                docblock. Jamais `markdown()` ici : la syntaxe resterait
                active et un nom contenant `[texte](url)` produirait un lien. --}}
            @php($_slugOrganization = currentOrganization()?->slug)
            @php($_lienProfil = \App\Support\Feed\FeedEventRenderer::lienProfil($post->user, $_slugOrganization))
            <div class="flex items-center gap-3" data-feed-event="{{ $post->type }}">
                @if($_lienProfil)
                    <a href="{{ $_lienProfil }}" class="shrink-0 rounded-full transition hover:ring-2 hover:ring-indigo-300 dark:hover:ring-indigo-600" aria-label="{{ $authorName }}">
                        <x-user-avatar :user="$post->user" size="sm" />
                    </a>
                @else
                    <x-user-avatar :user="$post->user" size="sm" class="shrink-0" />
                @endif

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
                `ms-10` = la largeur de l'avatar sm + l'ecart, pour que la
                boite s'aligne sous la phrase. --}}
            @php($_apercu = \App\Support\Feed\FeedEventRenderer::apercu($post, $_slugOrganization))
            @if($_apercu !== null)
                @php($_balise = $_apercu['url'] ? 'a' : 'div')
                <{{ $_balise }} @if($_apercu['url']) href="{{ $_apercu['url'] }}" @endif
                    class="ms-10 flex items-center gap-3 rounded-2xl border border-gray-100 bg-gray-50 p-3 transition dark:border-gray-800 dark:bg-gray-800/50 {{ $_apercu['url'] ? 'hover:bg-gray-100 dark:hover:bg-gray-800' : '' }}"
                    data-feed-event-apercu>
                    @if($_apercu['image'])
                        <img src="{{ $_apercu['image'] }}" alt=""
                             class="{{ $_apercu['ronde'] ? 'h-14 w-14 rounded-full' : 'h-14 w-20 rounded-xl' }} shrink-0 object-cover">
                    @elseif(! empty($_apercu['icone']))
                        {{-- TASK-1809 (correctif Cyril) — la MEME icone que le
                             rail gauche (« Entraide »), jamais une seconde
                             icone inventee pour le meme concept. --}}
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $_apercu['icone'] }}" />
                            </svg>
                        </span>
                    @endif
                    <span class="min-w-0 flex-1">
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
