<div class="flex flex-col h-full" wire:poll.3000ms x-on:reply-to-message.window="$wire.replyTo($event.detail.messageId)">
    <!-- Status banner -->
    @php
        $isDirectConversation = $transaction->isDirectConversation();
        $_threadOrgSlug = $organizationSlug ?? request()->route('organization');
        $_threadRoute = function (string $routeName, string $organizationRouteName, array $params = []) use ($_threadOrgSlug) {
            return $_threadOrgSlug && Route::has($organizationRouteName)
                ? route($organizationRouteName, array_merge(['organization' => $_threadOrgSlug], $params))
                : route($routeName, $params);
        };

        /* TASK-1704 — ce qu'une bulle doit savoir d'une piece jointe.
           Les URL passent par `$_threadRoute` : la conversation vit sur deux
           surfaces (courte et prefixee), et une piece doit rester sur celle
           ou le membre se trouve. */
        $_threadAttachment = function ($message) use ($_threadRoute) {
            if (! $message->hasFile()) {
                return null;
            }

            $params = ['transaction' => $message->transaction_id, 'message' => $message->id];

            return [
                'name' => $message->fileDisplayName(),
                'size' => $message->fileSizeForHumans(),
                'mime' => $message->file_mime,
                'downloadUrl' => $_threadRoute('messages.attachments.download', 'organization.messages.attachments.download', $params),
                'previewUrl' => $message->fileIsPreviewable()
                    ? $_threadRoute('messages.attachments.preview', 'organization.messages.attachments.preview', $params)
                    : null,
            ];
        };
    @endphp

    {{-- TASK-1693 bug AC — pour une DISCUSSION, cette bande ne portait plus
         que le badge : les actions y sont masquees et il n'y a pas de points.
         Le badge ayant rejoint la ligne du pseudo, la bande entiere disparait
         — une ligne de gagnee sur un ecran de telephone. Pour un ECHANGE elle
         reste : elle y porte le statut, les points et les boutons. --}}
    {{-- TASK-1755 §5.2 (maquette M2) — LA CARTE D'ENTRAIDE.

         Elle remplace un bandeau qui alignait jusqu'a QUATRE boutons de meme
         poids — « Accepter Refuser [30] Ajuster Annuler » — sans qu'une seule
         phrase dise QUI demandait QUOI a QUI. Constat de recette du 04/11.

         Quatre lignes, dans cet ordre, et l'ordre est le propos :
           1. l'annonce concernee, avec son badge court ;
           2. la PHRASE orientee role — c'est elle qui supprime la confusion ;
           3. l'etapier, qui dit ou on en est dans un parcours a quatre temps ;
           4. UNE action principale, le reste au menu « ⋯ ».

         Toutes les decisions viennent de `CarteEntraide`, jamais de la vue :
         les memes questions se posent dans la liste, et la liste est rendue
         deux fois. --}}
    @php
        $_carteUser = auth()->user();
        $_cartePhrase = \App\Support\Entraide\CarteEntraide::phrase($transaction, $_carteUser);
        $_carteEtape = \App\Support\Entraide\CarteEntraide::etape($transaction);
        $_carteActions = \App\Support\Entraide\CarteEntraide::actions($transaction, $_carteUser);
        $_carteUrl = fn (array $a) => $_threadRoute($a['route'], $a['routeOrg'], ['transaction' => $transaction]);
    @endphp
    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 {{ $isDirectConversation ? 'hidden' : '' }}"
         data-thread-status-banner data-entraide-carte>
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-2 min-w-0">
                @unless($isDirectConversation)
                    <span class="text-base" aria-hidden="true">🤝</span>
                    <span class="truncate font-semibold text-sm text-gray-900 dark:text-gray-100" data-entraide-objet>
                        {{ $transaction->subject }}
                    </span>
                @endunless
            </div>
            @unless($isDirectConversation)
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium flex-shrink-0
                    {{ match($transaction->status) {
                        'pending' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                        'accepted' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                        'buyer_done' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
                        'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                        default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                    } }}" data-entraide-badge>
                    {{ __('messages.status.' . $transaction->status) }}
                </span>
            @endunless
        </div>

        {{-- La phrase : le seul endroit qui dit le sens de l'echange. --}}
        @if($_cartePhrase)
            <p class="mt-1.5 text-sm text-gray-700 dark:text-gray-300" data-entraide-phrase>{{ $_cartePhrase }}</p>
        @endif

        {{-- L'etapier. Rien n'est allume quand l'echange est SORTI du parcours :
             un point allume ferait croire qu'il attend encore quelque chose. --}}
        @if($_carteEtape > 0)
            <ol class="mt-3 flex items-center" data-entraide-etapier="{{ $_carteEtape }}">
                @foreach(\App\Support\Entraide\CarteEntraide::ETAPES as $_i => $_etape)
                    @php $_atteinte = $_i < $_carteEtape; @endphp
                    @if($_i > 0)
                        <li aria-hidden="true" class="h-0.5 flex-1 {{ $_atteinte ? 'bg-indigo-500' : 'bg-gray-200 dark:bg-gray-700' }}"></li>
                    @endif
                    <li class="flex flex-col items-center gap-1 {{ $_i === 0 ? '' : 'ms-1' }} {{ $_i === count(\App\Support\Entraide\CarteEntraide::ETAPES) - 1 ? '' : 'me-1' }}">
                        <span class="block h-2.5 w-2.5 rounded-full {{ $_atteinte ? 'bg-indigo-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                        <span class="text-[10px] whitespace-nowrap {{ $_atteinte ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400' }}">
                            {{ __('messages.etapes.'.$_etape) }}
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- UNE action principale. Le reste — proposer un autre temps, annuler —
             vit dans le menu : ce sont les gestes rares, et les mettre au meme
             niveau rendait illisible ce qu'on attendait du lecteur. --}}
        @if($_carteActions['principale'] || $_carteActions['secondaire'] || $_carteActions['menu'])
            <div class="mt-3 flex flex-wrap items-center gap-2" data-entraide-actions>
                @if($_carteActions['principale'])
                    <form method="POST" action="{{ $_carteUrl($_carteActions['principale']) }}">
                        @csrf @method('PATCH')
                        {{-- TASK-1757 §9.5 — l'opt-in du Flux se prend ICI.
                             Arbitrage Cyril du 05/11 : plutot qu'un reglage a OFF
                             enterre dans une page que personne n'ouvre — donc une
                             fonctionnalite invisible —, la question se pose au moment
                             ou l'on decide. Decochee, aucune ligne n'est ecrite : il
                             n'y a rien a retenir, donc AUCUNE colonne nouvelle.
                             Seul l'aidant voit ce bouton (`CarteEntraide::actions()`),
                             et seul l'aidant est nomme sur la carte. --}}
                        @if($_carteActions['principale']['marqueur'] === 'confirm')
                            <label class="mb-2 flex items-start gap-2 text-xs text-gray-600 dark:text-gray-400">
                                <input type="checkbox" name="partager_dans_le_flux" value="1"
                                       data-entraide-partager-flux
                                       class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600">
                                <span>{{ __('entraide.flux.partager') }}</span>
                            </label>
                        @endif
                        <button data-entraide-action="{{ $_carteActions['principale']['marqueur'] }}"
                                class="px-4 py-1.5 text-sm font-medium text-white rounded-lg transition
                                    {{ ($_carteActions['principale']['ton'] ?? '') === 'vert' ? 'bg-green-600 hover:bg-green-700' : 'bg-indigo-600 hover:bg-indigo-700' }}">
                            {{ $_carteActions['principale']['libelle'] }}
                        </button>
                    </form>
                @endif

                @if($_carteActions['secondaire'])
                    <form method="POST" action="{{ $_carteUrl($_carteActions['secondaire']) }}">
                        @csrf @method('PATCH')
                        <button data-entraide-action="{{ $_carteActions['secondaire']['marqueur'] }}"
                                class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ $_carteActions['secondaire']['libelle'] }}
                        </button>
                    </form>
                @endif

                @if($_carteActions['menu'])
                    <div class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                        <button type="button" x-on:click="ouvert = ! ouvert"
                                aria-label="{{ __('messages.autres_actions') }}"
                                data-entraide-menu
                                class="px-2 py-1.5 text-sm rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700">⋯</button>
                        {{-- `absolute` est admis ICI : la carte n'est PAS dans un
                             conteneur a `overflow-x-auto` — c'est la colonne
                             Actions de /admin/users qui l'etait (T1693 bug D). --}}
                        <div x-show="ouvert" x-cloak x-on:click.outside="ouvert = false"
                             class="absolute z-20 mt-1 w-60 rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            @foreach($_carteActions['menu'] as $_acte)
                                <form method="POST" action="{{ $_carteUrl($_acte) }}" class="flex items-center gap-1 p-1">
                                    @csrf @method('PATCH')
                                    @if($_acte['champTemps'] ?? false)
                                        <input type="number" name="points_proposed" min="0"
                                               value="{{ $transaction->points_proposed }}"
                                               aria-label="{{ $_acte['libelle'] }}"
                                               class="w-20 rounded border px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                    @endif
                                    <button data-entraide-action="{{ $_acte['marqueur'] }}"
                                            class="flex-1 rounded px-2 py-1 text-start text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                        {{ $_acte['libelle'] }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Messages -->
    @php $canPin = in_array(auth()->id(), [$transaction->buyer_id, $transaction->seller_id]); @endphp

    <x-conversation.message-list>
        <x-slot:messages>
            <x-conversation.pinned-message-banner
                :pinned-message="$pinnedMessage"
                :can-unpin="$canPin"
            />

            @foreach($messages as $message)
                @php
                    $isPinned = $pinnedMessage?->id === $message->id;
                    $senderDisplayable = $message->sender?->isDisplayableIn(currentOrganization()) ?? false;
                    $senderName = $message->sender?->publicDisplayName() ?? __('messages.member');
                    $replySenderName = $message->replyTo?->sender?->publicDisplayName() ?? '';
                @endphp
                @if($message->isSystem())
                    <div class="flex justify-center">
                        {{-- TASK-1754 §3.4 — traduit si la clef est connue, sinon
                             le `body` tel qu'il a ete ecrit : c'est tout
                             l'historique anterieur au 05/11/2026, et c'est une
                             divergence ASSUMEE (arbitrage Q5, critere n°6). --}}
                        <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-full"
                              data-message-systeme>
                            {{ \App\Support\Entraide\MessageSysteme::rendre($message) }}
                        </span>
                    </div>
                @elseif($editingMessageId === $message->id)
                    {{-- TASK-1704 correctif 2 — le formulaire de modification,
                         de la meme forme que celui du ChatLoop : il PREND LA
                         PLACE de la bulle, plutot que de s'ouvrir a cote. --}}
                    <div class="flex justify-end" data-message-edit-form>
                        <form wire:submit="saveEdit" class="w-full max-w-[90%] sm:max-w-md md:max-w-lg rounded-2xl rounded-br-sm bg-indigo-600 p-3 text-white shadow-sm">
                            <textarea
                                wire:model="editingBody"
                                rows="3"
                                class="w-full rounded-lg border-indigo-400 bg-white/95 text-sm text-gray-900 focus:border-white focus:ring-white"
                            ></textarea>
                            @error('editingBody')
                                <p class="mt-1 text-xs text-indigo-100">{{ $message }}</p>
                            @enderror
                            <div class="mt-2 flex justify-end gap-2">
                                <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-indigo-100 hover:text-white">
                                    {{ __('messages.cancel_edit') }}
                                </button>
                                <button type="submit" class="rounded-lg bg-white px-3 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                    {{ __('messages.save') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @elseif($message->sender_id === auth()->id())
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="sent"
                        {{-- TASK-1704 correctif 2 — « il faudrait aussi pouvoir
                             modifier les messages textes, on peut le faire sur
                             la chatloop ». L'autorite reste au serveur : cette
                             prop ne fait qu'AFFICHER le bouton, et
                             `messageModifiable()` reevalue le droit au clic
                             comme a l'enregistrement. --}}
                        :show-edit-button="$message->isEditableBy(auth()->user())"
                        :time="$message->created_at->format('H:i')"
                        {{-- TASK-1693 bug Q — une correction par la moderation
                             doit se voir ici, exactement comme elle se voit
                             dans le ChatLoop. La bulle partagee le sait deja
                             faire ; seul ce fil ne le lui disait pas. --}}
                        :is-edited="$message->edited_at !== null"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => $replySenderName] : null"
                        :image-path="$message->imageUrl()"
                        :attachment="$_threadAttachment($message)"
                        :url-preview="$meta['url_preview'] ?? null"
                    >
                        {!! $message->body !!}
                    </x-conversation.message-bubble>
                @else
                    @php $meta = $message->metadata ?? []; @endphp
                    <x-conversation.message-bubble
                        type="received"
                        :time="$message->created_at->format('H:i')"
                        :name="$senderName"
                        :avatar="$senderDisplayable ? $message->sender?->avatar_url : null"
                        :is-edited="$message->edited_at !== null"
                        :message-id="$message->id"
                        :show-reply-button="true"
                        :show-pin-button="$canPin"
                        :is-pinned="$isPinned"
                        :show-reactions="$canPin"
                        :reaction-counts="$reactionData[$message->id] ?? []"
                        :my-reaction="$myReactions[$message->id] ?? null"
                        :reply-to="$message->replyTo ? ['body' => mb_substr($message->replyTo->body, 0, 120), 'sender_name' => $replySenderName] : null"
                        :image-path="$message->imageUrl()"
                        :attachment="$_threadAttachment($message)"
                        :url-preview="$meta['url_preview'] ?? null"
                    >
                        {!! $message->body !!}
                    </x-conversation.message-bubble>
                @endif
            @endforeach
        </x-slot:messages>

        <x-slot:after>
            <!-- Review form — visible after completion si pas encore noté -->
            {{-- TASK-1754 §9.4 (maquette M4) — « Dites merci » remplace
                 « Évaluez cet échange ».

                 Cinq etoiles et un « Commentaire (optionnel) », c'est Uber. La
                 hierarchie est inversee : le MOT devient le geste principal, la
                 note passe au second plan.

                 ## Pourquoi la note reste OBLIGATOIRE

                 Arbitrage Q6 de Cyril, pris sur une mesure : `reviews.rating`
                 est NOT NULL sans defaut, la moyenne du profil se calcule sur
                 TOUTES les notes (`User::recalculateRating()`) et `BadgeService`
                 s'en nourrit. La rendre optionnelle sans migration aurait oblige
                 a ecrire une note que personne n'a choisie — qui aurait compte
                 dans la moyenne affichee et dans l'attribution des badges. Et le
                 critere d'acceptation n°8 du CDC interdit la migration.

                 Elle est donc PRE-SELECTIONNEE et MODIFIABLE, et le libelle le
                 dit honnetement — « Vous pouvez ajuster la note », et non
                 « optionnelle » comme l'ecrit la maquette, ce qui serait faux. --}}
            @if($transaction->status === 'completed' && !$transaction->hasReviewFrom(auth()->id()))
            @php
                $_merciAutre = auth()->id() === $transaction->buyer_id ? $transaction->seller : $transaction->buyer;
                $_merciTemps = \App\Support\Entraide\TempsPartage::duree($transaction->points_agreed ?? $transaction->points_proposed);
            @endphp
            <div class="border-t-2 border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-4"
                 x-data="{ rating: 5, hovered: 0 }"
                 data-entraide-merci>
                <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                    🙏 {{ __('messages.review_exchange', ['prenom' => $_merciAutre?->first_name ?: $_merciAutre?->name]) }}
                </p>
                <p class="mt-1 mb-3 text-xs text-indigo-600/80 dark:text-indigo-300/80">
                    {{ __('messages.review_intro', ['temps' => $_merciTemps]) }}
                </p>
                <form method="POST" action="{{ route('reviews.store', $transaction) }}">
                    @csrf

                    {{-- Le MOT d'abord : c'est lui le geste. --}}
                    <textarea name="comment" rows="3" maxlength="1000"
                        placeholder="{{ __('messages.review_placeholder') }}"
                        data-entraide-merci-mot
                        class="w-full px-3 py-2 border border-indigo-200 dark:border-indigo-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 mb-3 resize-none"></textarea>

                    {{-- La note ensuite, petite, pre-selectionnee, modifiable. --}}
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <div class="flex gap-0.5" data-entraide-merci-note>
                            @for($i = 1; $i <= 5; $i++)
                            <button type="button"
                                @click="rating = {{ $i }}"
                                @mouseenter="hovered = {{ $i }}"
                                @mouseleave="hovered = 0"
                                aria-label="{{ $i }}/5"
                                class="text-base transition-transform hover:scale-110 focus:outline-none">
                                <span x-text="(hovered || rating) >= {{ $i }} ? '★' : '☆'"
                                      :class="(hovered || rating) >= {{ $i }} ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600'"></span>
                            </button>
                            @endfor
                        </div>
                        <input type="hidden" name="rating" :value="rating">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('messages.review_rating_hint') }}</span>
                    </div>

                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm rounded-lg font-medium transition"
                        data-entraide-merci-envoyer>
                        {{ __('messages.send_review') }}
                    </button>
                </form>
            </div>
            @endif
        </x-slot:after>
    </x-conversation.message-list>

    <!-- Input -->
    @if(!in_array($transaction->status, ['completed', 'refused', 'cancelled']))
        <x-conversation.composer
            model="newMessage"
            :placeholder="__('messages.composer_placeholder')"
            :replying-to="$replyingTo"
            on-cancel-reply="cancelReply"
            show-upload="true"
            :photo="$photo ?? null"
            {{-- TASK-1704 — seule la Messagerie sait stocker autre chose
                 qu'une image : elle seule elargit le selecteur. --}}
            :upload-accept="\App\Support\Files\TypesDeFichiersAcceptes::pourAttributAccept()"
            :upload-label="__('messages.send_file')"
            {{-- TASK-1772 (recette Cyril, 06/11) — « partager une video ».
                 Seule la Messagerie l'active : elle seule valide les types
                 video cote serveur (`TypesDeFichiersAcceptes::VIDEO`). --}}
            :show-video-upload="true"
        >
            {{-- TASK-1772 (recette Cyril, 06/11) — LA MEME PILULE QUE LE CHATLOOP.
                 =====================================================================
                 « Le chat composer de la messagerie privee n'est pas le meme que
                 celui des ChatLoops. Il faut qu'il soit identique. »

                 Il suffit de FOURNIR CE SLOT : `composer.blade.php` bascule en
                 gabarit pilule des que `$leading` existe (T1769). Les deux boutons
                 ronds exterieurs (galerie, appareil photo) se rangent alors d'eux
                 memes derriere le « + » — ils sont `hidden md:flex` et
                 `@if(! isset($leading))`. Rien n'est duplique : la feuille invoque
                 les MEMES saisies de fichier, donc le meme `photo`, donc le meme
                 chemin serveur.

                 Ce qui differe du ChatLoop, et c'est voulu : pas d'IA, pas de
                 Dossiers, pas de mentions. Cet ecran n'est pas une Boucle. --}}
            <x-slot:leading>
                <div class="md:hidden" x-data="{ sheetOpen: false }">
                    <button
                        type="button"
                        x-on:click="sheetOpen = true"
                        class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 transition hover:bg-gray-200 hover:text-indigo-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:hover:text-indigo-300"
                        aria-label="{{ __('messages.composer_more_actions') }}"
                        aria-haspopup="true"
                        data-composer-sheet-trigger
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    </button>
                    <template x-teleport="body">
                        <div
                            x-show="sheetOpen"
                            x-cloak
                            class="fixed inset-0 z-50 flex items-end justify-center"
                            x-effect="document.body.style.overflow = sheetOpen ? 'hidden' : ''"
                            @keydown.escape.window="sheetOpen = false"
                        >
                            <div x-show="sheetOpen" class="fixed inset-0 bg-black/50" x-on:click="sheetOpen = false"></div>
                            <div
                                x-show="sheetOpen"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="translate-y-full opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                class="relative w-full max-w-lg rounded-t-2xl bg-white p-4 shadow-2xl dark:bg-gray-800"
                                style="padding-bottom: calc(1rem + env(safe-area-inset-bottom, 0px))"
                                role="dialog"
                                aria-modal="true"
                                aria-label="{{ __('messages.composer_more_actions') }}"
                            >
                                <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                                {{-- Trois par rangee, comme la feuille du ChatLoop : la
                                     quatrieme tuile passe a la ligne, elle ne retrecit
                                     pas les autres. --}}
                                <div class="grid grid-cols-3 gap-1.5">
                                    <button type="button" x-on:click="$refs.imageInput?.click(); sheetOpen = false"
                                        data-composer-sheet-image
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('messages.composer_add_image') }}</span>
                                    </button>
                                    {{-- `data-composer-camera` : c'est le marqueur que lit
                                         `TASK1693MobileMessagingTest`. Il vivait sur le bouton rond
                                         exterieur, qui disparait avec la pilule — il DOIT donc
                                         voyager ici, sans quoi la garde rougirait a juste titre. --}}
                                    <button type="button" x-on:click="$refs.cameraInput?.click(); sheetOpen = false"
                                        data-composer-take-photo
                                        data-composer-camera
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('messages.composer_camera') }}</span>
                                    </button>
                                    {{-- `uploadInput` porte la liste ELARGIE de la Messagerie :
                                         c'est la tuile qui ouvre tout ce que le serveur sait
                                         stocker. --}}
                                    <button type="button" x-on:click="$refs.uploadInput?.click(); sheetOpen = false"
                                        data-composer-sheet-file
                                        class="flex flex-col items-center gap-1.5 rounded-xl px-1.5 py-2 text-center transition hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                        </span>
                                        <span class="text-[11px] font-medium leading-tight text-gray-700 dark:text-gray-200">{{ __('messages.composer_add_file') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </x-slot:leading>
            @error('newMessage')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
            @error('photo')
                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
            @enderror
        </x-conversation.composer>
    @else
    <div class="border-t border-gray-200 dark:border-gray-700 p-4 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('messages.conversation_closed') }}
    </div>
    @endif
</div>

{{-- TASK-1771 — meme barre que sur le ChatLoop : le composant de bulle est
     partage, le geste doit l'etre aussi. --}}
<x-conversation.selection-bar />

<script>
    function syncUnreadBadge(count) {
        const link = document.querySelector('a[href="{{ $_threadRoute('messages.index', 'organization.messages.index') }}"]');
        if (!link) return;
        const existing = link.querySelector('.bg-red-500');
        if (count > 0) {
            const label = count > 9 ? '9+' : count;
            if (existing) {
                existing.textContent = label;
            } else {
                const badge = document.createElement('span');
                badge.className = 'absolute -top-2 -right-4 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center';
                badge.textContent = label;
                link.querySelector('.relative')?.appendChild(badge);
            }
        } else if (existing) {
            existing.remove();
        }
    }

    document.addEventListener('livewire:updated', () => {
        syncUnreadBadge({{ $unreadCount }});
    });
</script>
