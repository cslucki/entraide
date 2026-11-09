@props([
    'model' => 'message',
    'placeholder' => null,
    'disabled' => false,
    'loading' => false,
    'error' => null,
    'rows' => 1,
    'replyingTo' => null,
    'onCancelReply' => null,
    'showUpload' => false,
    'photo' => null,
    // TASK-1704 — ce que le bouton de la GALERIE propose.
    //
    // Le defaut reste `image/*`, et c'est deliberement conservateur : ce
    // composant est partage avec le ChatLoop et le chat agent, dont les
    // serveurs ne valident que des images. Elargir ici pour tout le monde
    // aurait fait proposer aux membres un geste que le serveur refuse — le
    // defaut exact que TASK-1684 avait corrige sur les Dossiers, ou l'ecran
    // offrait « Filmer une video » pendant que la regle la rejetait.
    //
    // Seule la Messagerie passe la liste elargie, parce que seule elle sait
    // stocker autre chose qu'une image. Le bouton APPAREIL PHOTO, lui, garde
    // `image/*` en toute circonstance : `capture` n'a de sens que pour un
    // cliche.
    'uploadAccept' => 'image/*',
    'uploadLabel' => null,
    // TASK-1308 : moteur du prochain tour ('ia'|'dossiers'|null), affiche en
    // chip compact au-dessus du composeur — jamais un verrou (voir $onClearMode).
    // TASK-1309 : quatrieme etat 'ia_dossiers' (IA + Dossiers).
    'mode' => null,
    'modeLabel' => null,
    'onClearMode' => null,
    // TASK-1772 — les personnes mentionnables, OPT-IN.
    //
    // Defaut vide, et SEUL le ChatLoop la passe : la Messagerie et le chat
    // agent gardent leur comportement a l'octet. Meme discipline que
    // `uploadAccept` en T1704, et c'est tout l'interet d'un composant
    // partage — on ajoute pour un appelant sans toucher les deux autres.
    'mentionables' => [],
    // TASK-1797 — la mention collective, OPT-IN elle aussi, et DOUBLEMENT :
    // seul le ChatLoop la passe (meme discipline que `mentionables`), et
    // seul un proprietaire/animateur de la Boucle courante la recoit `true`
    // (voir `LoopChat::$peutMentionnerTous`). `false` ne fait disparaitre
    // qu'une OPTION d'affichage ; l'autorisation reelle est re-controlee au
    // serveur, a l'envoi.
    'canMentionAll' => false,
])

@php
    $placeholder ??= __('messages.write_message');
    $uploadLabel ??= __('messages.send_image');
@endphp

{{-- Le gabarit compact mobile est SCOPE au ChatLoop (seul appelant qui
     fournit `$leading`) : Message Thread et le chat agent gardent leur
     gabarit historique a l'identique. --}}
<div class="border-t border-gray-200 dark:border-gray-700 {{ isset($leading) ? 'px-3 py-2.5 md:px-5 md:py-4' : 'px-5 py-4' }}">
    @if($replyingTo)
    <div class="flex items-center justify-between mb-2 px-3 py-1.5 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg text-xs">
        <span class="text-gray-600 dark:text-gray-300 truncate">
            <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ __('messages.reply_to') }}</span>
            <span class="text-gray-500 dark:text-gray-400">{{ $replyingTo['sender_name'] ?? '' }} :</span>
            <span class="text-gray-400 dark:text-gray-500 truncate ml-1">{{ $replyingTo['body'] }}</span>
        </span>
        @if($onCancelReply)
        <button
            wire:click="{{ $onCancelReply }}"
            class="flex-shrink-0 ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
        @endif
    </div>
    @endif

    {{-- TASK-1308 : chip compacte du moteur choisi — sections 30/31/37.
         `$onClearMode` ramene le composeur en mode normal ; le mode n'est
         jamais un verrou, seulement un DEFAUT visible. --}}
    @if($mode)
    <div class="flex items-center gap-2 mb-2">
        <span data-composer-mode="{{ $mode }}" class="inline-flex items-center gap-1.5 rounded-full {{ match ($mode) {
                'dossiers' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200',
                'ia_dossiers' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200',
                default => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200',
            } }} px-2.5 py-1 text-xs font-semibold">
            {{ $modeLabel }}
            @if($onClearMode)
            <button type="button" wire:click="{{ $onClearMode }}" class="ml-0.5 -mr-1 inline-flex h-4 w-4 items-center justify-center rounded-full text-current transition hover:bg-black/10 dark:hover:bg-white/10" aria-label="{{ __('loops.composer_mode_clear') }}">
                <span aria-hidden="true">×</span>
            </button>
            @endif
        </span>
    </div>
    @endif

    @if($showUpload && $photo)
    <div class="flex items-center gap-2 mb-2 px-3 py-2 bg-gray-50 dark:bg-gray-900 rounded-lg">
        <img src="{{ $photo->temporaryUrl() }}" class="w-10 h-10 rounded object-cover flex-shrink-0" alt="{{ __('messages.image_preview') }}">
        <span class="text-xs text-gray-500 dark:text-gray-400 truncate flex-1">{{ $photo->getClientOriginalName() }}</span>
        <button
            wire:click="removePhoto"
            class="flex-shrink-0 text-gray-400 hover:text-red-500 transition"
        >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    @endif

    {{-- Racine Alpine du composeur : elle permet au menu mobile (`$leading`,
         TASK-1308) de declencher le selecteur de fichier via `$refs`, et porte
         `hasText` (TASK-1329) — la cinematique du bouton envoyer, gris et en
         retrait tant que le champ est vide, indigo et a pleine taille des le
         premier caractere (motif des messageries mobiles). Etat VISUEL
         seulement : le bouton reste cliquable a vide (une photo seule
         s'envoie), le serveur valide. --}}
    {{-- TASK-1684 — `uploading` ferme une course REPRODUITE en recette mobile.
         Une image choisie monte par `wire:model.live` (requete separee). Tant
         qu'elle n'est pas arrivee, le serveur ne la connait pas : un appui sur
         « envoyer » pendant la montee part donc avec `body` vide ET `photo`
         null. Deux degats, pas un : le serveur repond « Ajoutez un message ou
         une image. » — faux, l'image EST choisie — et sa reponse (photo null)
         ECRASE l'upload qui vient de se terminer, l'image est AVALEE en
         silence et le second appui n'envoie rien.
         Les evenements d'upload de Livewire remontent (`bubbles: true`) : les
         poser sur le `<form>` couvre les DEUX saisies de fichier (galerie et
         appareil photo) sans ecouteur par input. --}}
    <form wire:submit="sendMessage" class="flex items-end {{ isset($leading) ? 'gap-2 md:gap-3' : 'gap-3' }}"
        {{-- TASK-1684 — `enterEnvoie` : Entree n'envoie que sur un clavier
             PHYSIQUE. Sur un clavier tactile il n'existe aucun Maj+Entree, donc
             intercepter Entree retirait purement et simplement le retour a la
             ligne — rapporte en recette mobile. Le discriminant est le POINTEUR
             (`hover: none` + `pointer: coarse`), pas la largeur : une fenetre
             etroite sur un portable garde son clavier et doit garder l'envoi
             par Entree. Evalue une fois au montage ; sur mobile l'envoi passe
             par le bouton, qui est a cote du pouce. --}}
        x-data="{
            hasText: false,
            uploading: false,
            enterEnvoie: ! window.matchMedia('(hover: none) and (pointer: coarse)').matches,

            /* ===== TASK-1772 — le menu predictif des mentions =====
               Tout est CLIENT : la Boucle la plus peuplee compte quinze
               membres actifs, une recherche serveur par frappe n'a aucune
               justification a cette echelle. */
            mentionables: @js(array_values($mentionables)),
            mentionOuvert: false,
            mentionFiltre: '',
            mentionIndex: 0,
            mentionsChoisies: [],

            /* TASK-1797 — la mention collective, OPT-IN cote serveur (voir
               la liste des proprietes du composant, en haut du fichier).
               N'autorise rien a elle seule : voir le controle serveur, et
               le docblock PHP de LoopChat::$peutMentionnerTous. */
            canMentionAll: @js((bool) $canMentionAll),
            confirmTousOuvert: false,

            get mentionResultats() {
                if (! this.mentionOuvert) return [];
                const f = this.mentionFiltre.toLowerCase();
                const resultats = this.mentionables
                    .filter((m) => f === '' || m.nom.toLowerCase().includes(f));

                /* TASK-1797 — « Tous les membres » vit DANS la meme liste,
                   filtree par le MEME texte : pas de second menu, pas de
                   seconde navigation clavier. `special` la distingue au
                   rendu et dans `mentionChoisir()`, qui ne la pousse pas
                   dans `mentionsChoisies` — ce n'est pas une mention
                   individuelle, le plafond de dix ne doit pas la voir. */
                if (this.canMentionAll && (f === '' || '{{ \App\Livewire\LoopChat::MENTION_ALL_TOKEN }}'.includes(f))) {
                    resultats.unshift({
                        id: '__mention_all__',
                        nom: '{{ \App\Livewire\LoopChat::MENTION_ALL_TOKEN }}',
                        libelle: @js(__('loops.mention_all_option')),
                        special: true,
                    });
                }

                return resultats.slice(0, 8);
            },

            /* Le `@` en cours de frappe, lu AVANT le curseur. On n'ouvre que
               sur un `@` precede d'un debut de ligne ou d'une espace : sans
               cela, une adresse email ouvrirait le menu a chaque frappe. */
            mentionDetecter(el) {
                const avant = el.value.slice(0, el.selectionStart);
                const m = avant.match(/(^|\s)@([^\s@]{0,30})$/);

                if (m === null) { this.mentionOuvert = false; return; }

                this.mentionOuvert = true;
                this.mentionFiltre = m[2];
                this.mentionIndex = 0;
            },

            mentionChoisir(el, personne) {
                const avant = el.value.slice(0, el.selectionStart);
                const apres = el.value.slice(el.selectionStart);
                const remplace = avant.replace(/(^|\s)@([^\s@]{0,30})$/, '$1@' + personne.nom + ' ');

                el.value = remplace + apres;
                const curseur = remplace.length;
                el.setSelectionRange(curseur, curseur);

                // TASK-1797 — `@tous` n'est PAS une mention individuelle :
                // elle ne doit ni compter dans le plafond de dix
                // (`MENTIONS_MAX`), ni etre cherchee parmi les membres reels
                // par `mentionsRecadrees()`, qui ne la trouverait pas.
                if (! personne.special && ! this.mentionsChoisies.includes(personne.id)) {
                    this.mentionsChoisies.push(personne.id);
                }

                this.mentionOuvert = false;
                this.mentionFiltre = '';

                /* PIEGE P1 — `wire:model` est DIFFERE. Sans cet evenement, le
                   texte est a l'ecran et le serveur recevra la valeur d'AVANT
                   l'insertion ; `hasText` resterait faux et le bouton
                   d'envoi paraitrait mort. Defaut deja paye en T1622, qui a
                   du ajouter l'ecouteur `composer-filled` pour la meme
                   raison : une ecriture qui ne vient pas du clavier ne
                   declenche ni `input` ni la mise a jour de `hasText`. */
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.focus();

                /* Deferee : pas de requete immediate. Le serveur re-cadrera
                   de toute facon cette liste a l'envoi — rien de ce qui part
                   d'ici n'est cru sur parole. */
                $wire.set('mentionsSelectionnees', this.mentionsChoisies, false);
            },

            mentionClavier(e, el) {
                if (! this.mentionOuvert || this.mentionResultats.length === 0) return false;

                if (e.key === 'ArrowDown') { e.preventDefault(); this.mentionIndex = (this.mentionIndex + 1) % this.mentionResultats.length; return true; }
                if (e.key === 'ArrowUp') { e.preventDefault(); this.mentionIndex = (this.mentionIndex - 1 + this.mentionResultats.length) % this.mentionResultats.length; return true; }
                if (e.key === 'Enter') { e.preventDefault(); this.mentionChoisir(el, this.mentionResultats[this.mentionIndex]); return true; }
                if (e.key === 'Escape') { e.preventDefault(); this.mentionOuvert = false; return true; }

                return false;
            },

            /* TASK-1797 — point d'entree UNIQUE de l'envoi, pour le bouton
               ET la touche Entree : un geste aussi consequent qu'une mention
               collective ne doit pas dependre de LAQUELLE des deux voies on
               a prise pour s'en souvenir.
               Cote CLIENT uniquement : une detection approximative n'a rien
               d'une garde — le serveur refuse de toute facon un `@tous`
               non autorise (voir `LoopChat::sendMessage()`), ceci n'est que
               la confirmation d'un geste pour qui a REELLEMENT le droit. */
            tenterEnvoi(el) {
                if (this.canMentionAll && /@tous\b/i.test(el.value)) {
                    this.confirmTousOuvert = true;
                    return;
                }

                $wire.sendMessage();
            },
        }"
        x-on:livewire-upload-start="uploading = true"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-cancel="uploading = false">
        @if($showUpload)
        {{-- TASK-1693 bug AC — ce bouton n'avait aucun intitule : ni infobulle,
             ni libelle accessible. On ne devinait pas ce qu'il faisait. --}}
        <label data-composer-upload
               aria-label="{{ $uploadLabel }}"
               title="{{ $uploadLabel }}"
               class="{{ isset($leading) ? 'hidden md:flex' : 'flex' }} mb-1 flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 items-center justify-center cursor-pointer transition disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <input type="file" x-ref="uploadInput" wire:model.live="photo" accept="{{ $uploadAccept }}" class="hidden">
        </label>
        {{-- TASK-1329 : second declencheur du MEME upload (`photo`), avec
             `capture` — sur mobile, il ouvre directement l'appareil photo au
             lieu de la galerie. Aucun pipeline nouveau : le fichier suit
             exactement le meme chemin que la galerie. Inerte tant qu'aucun
             bouton ne l'invoque (`$refs.cameraInput`, menu mobile T1308). --}}
        {{-- TASK-1772 (recette Cyril, 06/11, PWA Android) — `accept="image/*"`
             ET RIEN D'AUTRE.
             J'avais elargi l'accept aux deux types (images ET videos) pour
             obtenir la bascule Photo/Video de WhatsApp. Mesure de Cyril sur son Android : le
             bouton « ouvre la galerie photo et non l'appareil ». C'est le
             comportement connu de Chrome Android — `capture` n'est honore
             que sur un `accept` d'UN SEUL type ; avec deux, l'attribut est
             ignore et le selecteur generique s'ouvre.
             Une camera qui ouvre la galerie est pire que pas de camera : la
             promesse du libelle est fausse. Une video se joint donc par
             « Ajouter un fichier », dont la liste elargie les contient. --}}
        <input type="file" x-ref="cameraInput" wire:model.live="photo" accept="image/*" capture="environment" class="hidden">

        {{-- TASK-1772 — la GALERIE D'IMAGES, distincte de `uploadInput`.
             Dans la Messagerie, `uploadInput` porte la liste elargie (images,
             documents, audio, video) : son selecteur montre tout. « Ajouter
             une image » doit, lui, ne montrer que des images. Meme modele
             `photo`, meme chemin serveur — seul l'`accept` change.
             Inerte tant qu'aucun bouton ne l'invoque, comme `cameraInput`. --}}
        <input type="file" x-ref="imageInput" wire:model.live="photo" accept="image/*" class="hidden">
        {{-- TASK-1693 bug AC — le declencheur de l'appareil photo, enfin pose.
             Le champ ci-dessus existait depuis TASK-1329 mais restait INERTE
             hors du ChatLoop : aucun bouton ne l'invoquait. Aucun pipeline
             nouveau — le cliche suit exactement le chemin de la galerie.

             `md:hidden` : `capture` n'a de sens que sur un appareil qui a une
             camera en facade. Sur un ordinateur, le bouton ouvrirait un
             selecteur de fichiers identique au premier, donc deux boutons pour
             un meme geste. --}}
        @if(! isset($leading))
        <button type="button"
                data-composer-camera
                x-on:click="$refs.cameraInput.click()"
                aria-label="{{ __('messages.take_photo') }}"
                title="{{ __('messages.take_photo') }}"
                class="md:hidden mb-1 flex flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 items-center justify-center cursor-pointer transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
        </button>
        @endif
        @endif

        {{-- TASK-1329 : le declencheur du menu mobile (`$leading`, le « + »)
             vit DANS le cadre du champ, plus a cote de lui — un bouton rond
             externe consommait ~3rem de largeur sur un ecran qui n'en a pas
             (motif des messageries mobiles). `items-end` + `pb` : il suit le
             BAS du champ quand le textarea grandit, comme le bouton envoyer.
             Le textarea DOIT etre `block` : en inline-block (defaut), le
             wrapper garde ~4px de descendante sous la ligne de base, le champ
             remonte d'autant et le bouton envoyer comme le « + » paraissent
             cales trop bas. --}}
        {{-- TASK-1769 — la PILULE. Sous 768 px, le cadre n'est plus le
             `<textarea>` lui-meme : c'est ce conteneur, qui porte la bordure,
             le fond et le rayon, et qui range ses poignees EN FLUX a cote du
             champ. L'absolu d'avant ne pouvait pas tenir : la pastille de mode
             a une largeur variable (« IA », « IA + Dossiers »), et un texte
             qui passe dessous se lit par-dessus elle.
             A partir de `md` le conteneur redevient transparent et le
             `<textarea>` reprend sa bordure : le bureau ne bouge pas d'un
             pixel, la Messagerie et le chat agent non plus (ils n'ont pas
             `$leading`). --}}
        <div class="relative min-w-0 flex-1 {{ isset($leading)
            ? 'flex items-end gap-1 rounded-[23px] border border-gray-300 bg-white px-[5px] py-[4px] shadow-sm transition focus-within:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 md:block md:rounded-none md:border-0 md:bg-transparent md:p-0 md:shadow-none md:focus-within:border-transparent'
            : '' }}">
            @if($mentionables !== [])
            {{-- TASK-1772 — LE MENU PREDICTIF.
                 Pose dans le conteneur du champ, en ABSOLU au-dessus : il doit
                 couvrir la conversation, jamais pousser le composeur — sinon
                 le fil sauterait a chaque `@`.
                 `data-mention-*` sont les ancrages de test, convention du
                 depot (vingt et un `data-*` vivent deja dans
                 `loop-chat.blade.php`). --}}
            <div data-mention-menu
                 x-show="mentionOuvert && mentionResultats.length > 0"
                 x-cloak
                 x-on:click.outside="mentionOuvert = false"
                 class="absolute bottom-full left-0 z-30 mb-2 max-h-64 w-full overflow-y-auto overscroll-contain rounded-2xl border border-gray-200 bg-white p-1.5 shadow-2xl dark:border-gray-700 dark:bg-gray-800">
                <template x-for="(personne, i) in mentionResultats" :key="personne.id">
                    <button type="button"
                            x-bind:data-mention-option="personne.id"
                            {{-- TASK-1772 (recette Cyril) — `$el.closest(...)` et
                                 NON `$refs.champMessage`.
                                 Le `<textarea>` porte son PROPRE `x-data`
                                 (`resize()`), donc son `x-ref` s'enregistre sur
                                 lui-meme et pas sur le formulaire. Depuis ce
                                 bouton, `$refs.champMessage` valait `undefined`
                                 et le clic levait « Cannot read properties of
                                 undefined (reading 'value') » — le menu
                                 s'ouvrait et rien ne se passait au clic.
                                 Mesure : les refs visibles ici sont
                                 `uploadInput` et `cameraInput`, pas le champ.
                                 C'est le piege deja ecrit dans les regles du
                                 depot : « `$refs` ne traverse pas un composant
                                 Alpine, passer par `$el.querySelector()` ». --}}
                            x-on:click="mentionChoisir($el.closest('form').querySelector('textarea'), personne)"
                            x-bind:class="i === mentionIndex ? 'bg-indigo-50 dark:bg-indigo-900/30' : ''"
                            class="flex min-h-11 w-full items-center gap-2 rounded-xl px-3 text-left text-sm text-gray-800 transition hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">
                        {{-- TASK-1797 — « Tous les membres » porte un glyphe
                             distinct (le symbole de la mention elle-meme),
                             jamais des initiales qui ne representeraient
                             personne. --}}
                        <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                              x-bind:class="personne.special
                                  ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-200'
                                  : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-200'"
                              x-text="personne.special ? '@' : personne.nom.trim().split(/\s+/).slice(0, 2).map((m) => m[0]).join('').toUpperCase()"></span>
                        <span class="truncate" x-text="personne.libelle ?? personne.nom"></span>
                    </button>
                </template>
            </div>
            @endif
            @isset($leading)
            {{-- TASK-1769 — la gauche de la pilule. L'appelant y met ce qu'il
                 veut : le ChatLoop y met le « + » ET sa porte vers l'IA. Le
                 composeur, lui, reste ignorant des modes — il est partage avec
                 la Messagerie et le chat agent. --}}
            <div class="flex min-w-0 shrink-0 items-end gap-1 md:hidden">
                {{ $leading }}
            </div>
            @endisset
            {{-- TASK-1329 : `wire:ignore.self` — le morph du `wire:poll`
                 realignait les attributs sur le HTML serveur et EFFACAIT la
                 hauteur `style` posee par `resize()` (piege connu du depot,
                 meme motif que le bloc sources T1312) : un brouillon de trois
                 lignes retombait visuellement a une seule au poll suivant,
                 constate en recette mobile. Le serveur n'ecrit JAMAIS de
                 style sur ce champ, geler ses attributs est sans perte ; la
                 saisie et le reset apres envoi passent par `wire:model`,
                 un canal distinct du morph d'attributs. --}}
            <textarea
                wire:ignore.self
                data-composer-input
                x-data="{ resize() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 176) + 'px'; $el.scrollTop = $el.scrollHeight } }"
                x-init="resize(); hasText = $el.value.trim().length > 0"
                x-on:input="resize(); hasText = $el.value.trim().length > 0; @if($mentionables !== []) mentionDetecter($el) @endif"
                x-on:message-sent.window="$nextTick(() => { resize(); hasText = $el.value.trim().length > 0 })"
                {{-- TASK-1622 — une ecriture SERVEUR de la valeur (une
                     reformulation posee dans le composeur) ne declenche ni
                     `input` ni `message-sent`. Sans cet ecouteur, la hauteur
                     ne s'ajuste pas et surtout `hasText` reste faux : le
                     bouton d'envoi resterait desactive devant un texte
                     pourtant visible. On rend aussi le focus, curseur en fin
                     de texte, pour que le membre puisse modifier tout de
                     suite. --}}
                x-on:composer-filled.window="$nextTick(() => { resize(); hasText = $el.value.trim().length > 0; $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) })"
                wire:model="{{ $model }}"
                rows="{{ $rows }}"
                {{-- TASK-1684 — la touche Entree est un SECOND chemin d'envoi, qui
                     ne passe pas par le bouton : la garde `uploading` doit y etre
                     aussi, sinon la course reste ouverte au clavier. --}}
                {{-- TASK-1772 — le menu prend le clavier EN PREMIER quand il
                     est ouvert : Entree choisit la personne au lieu d'envoyer
                     le message. `mentionClavier()` rend `true` quand il a
                     consomme la touche. --}}
                @if($mentionables !== [])
                {{-- TASK-1772 (recette Cyril) — `stopImmediatePropagation` et
                     NON `stopPropagation`.
                     `stopPropagation` arrete la REMONTEE vers les ancetres ;
                     elle ne dit rien des autres ecouteurs poses sur LE MEME
                     element. Le `@keydown.enter` ci-dessous s'executait donc
                     juste apres, et comme `mentionChoisir()` venait de
                     refermer le menu, sa garde `mentionOuvert` ne voyait plus
                     rien : le message partait a la seconde ou l'on choisissait
                     la personne, sans laisser le temps d'ecrire. --}}
                x-on:keydown="if (mentionClavier($event, $el)) { $event.stopImmediatePropagation() }"
                @endif
                {{-- TASK-1797 — la substitution par `tenterEnvoi()` est elle-meme
                     OPT-IN, et ce n'est pas cosmetique : des suites entieres
                     (`TASK1365ShellComposerUxTest`, `LoopChatTest`) relisent le
                     texte LITTERAL de ce gestionnaire pour prouver que le
                     « contrat clavier » du composeur est identique partout —
                     Messagerie, chat agent, ET ChatLoop pour qui n'a pas le
                     droit `@tous`. Une substitution inconditionnelle aurait
                     change ce texte pour TOUT LE MONDE et fait rougir ces
                     gardes sans rien changer au comportement reel. --}}
                @if($canMentionAll)
                @keydown.enter="if (mentionOuvert ?? false) { return } /* ceinture : la bretelle est le stopImmediatePropagation ci-dessus */ if (enterEnvoie && !$event.shiftKey) { $event.preventDefault(); if (!uploading) { tenterEnvoi($el) } }"
                @else
                @keydown.enter="if (mentionOuvert ?? false) { return } /* ceinture : la bretelle est le stopImmediatePropagation ci-dessus */ if (enterEnvoie && !$event.shiftKey) { $event.preventDefault(); if (!uploading) { $wire.sendMessage() } }"
                @endif
                {{-- TASK-1769 — deux listes ENTIERES plutot qu'une liste avec
                     des `md:` greffes : la liste de droite est celle d'avant,
                     octet pour octet, donc la Messagerie et le chat agent sont
                     prouves inchanges par simple lecture du diff. --}}
                class="{{ isset($leading)
                    ? 'block max-h-44 min-h-9 w-full resize-none overflow-y-hidden border-0 bg-transparent px-1 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-none transition [scrollbar-width:none] focus:outline-none focus:ring-0 dark:text-gray-100 [&::-webkit-scrollbar]:hidden md:min-h-11 md:rounded-2xl md:border md:border-gray-300 md:bg-white md:px-4 md:py-3 md:shadow-sm md:focus:border-indigo-500 md:focus:ring-2 md:focus:ring-indigo-500/20 md:dark:border-gray-600 md:dark:bg-gray-900'
                    : 'block max-h-44 min-h-11 w-full resize-none overflow-y-hidden rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition [scrollbar-width:none] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 [&::-webkit-scrollbar]:hidden' }}"
                placeholder="{{ $placeholder }}"
                @if($disabled || $loading) disabled @endif
            ></textarea>
            @isset($leading)
            {{-- TASK-1771 (demande Cyril) — plus d'appareil photo dans la
                 pilule. T1769 l'y avait amene pour retirer le DOUBLON avec la
                 tuile de la feuille du « + » ; Cyril tranche dans l'autre
                 sens : une seule porte, et c'est la feuille. La pilule garde
                 donc « + » a gauche, le champ, et rien a droite.
                 Le champ de saisie `cameraInput` reste la, plus haut : c'est
                 la feuille qui l'invoque. --}}
            @endisset
        </div>

        {{-- TASK-1329 : `mb-1` cale le bouton (36px) sur l'axe du champ
             (44px min) — il etait pose sur la ligne de base du flex, en
             dessous du cadre. La couleur et l'echelle suivent CAN_SEND —
             texte non vide OU piece jointe prete — jamais le texte seul :
             une photo sans legende est un envoi valide, le bouton ne doit
             pas paraitre eteint ({{ '$photo' }} est re-rendu par le morph a
             chaque upload, l'expression suit). `active:scale-90` donne le
             pop tactile a l'envoi. Etat VISUEL seulement : le bouton reste
             cliquable, le serveur valide. --}}
        <button
            type="submit"
            @if($canMentionAll)
            {{-- TASK-1797 — `preventDefault()` sur le CLIC d'un bouton
                 `type="submit"` annule sa soumission par defaut, exactement
                 comme sur l'evenement `submit` lui-meme : on n'a pas besoin
                 de faire la course avec l'ecouteur `wire:submit` du
                 formulaire, on empeche simplement le clic de le declencher.
                 `tenterEnvoi()` lit le `<textarea>` du MEME formulaire —
                 jamais `$refs`, qui ne traverse pas ce composant Alpine
                 (piege deja ecrit plus haut dans ce fichier pour la meme
                 raison). --}}
            x-on:click="if (canMentionAll && /@tous\b/i.test($el.closest('form').querySelector('textarea').value)) { $event.preventDefault(); confirmTousOuvert = true }"
            @endif
            {{-- TASK-1769 — 46 px sur telephone, a l'axe exact de la pilule
                 (46 px aussi), donc `mb-0` : `mb-1` servait a caler un bouton
                 de 36 px sur un champ de 44. Au-dessus de `md`, les cotes
                 d'avant reviennent. --}}
            class="{{ isset($leading) ? 'mb-0 h-[46px] w-[46px] md:mb-1 md:h-9 md:w-9' : 'mb-1 h-9 w-9' }} flex flex-shrink-0 items-center justify-center rounded-full transition-all duration-200 ease-out active:scale-90 disabled:opacity-50"
            {{-- TASK-1365 : la couleur active vient du THEME (--bp-primary, « Action
                 principale » de /admin/themes) et non plus d'un indigo en dur. Le Shell
                 utilise exactement le meme token : les deux composers changent ensemble
                 quand le locataire change de theme.
                 L'ECHELLE, l'etat inactif et le contrat CAN_SEND de T1329 sont INCHANGES —
                 seule la teinte active bouge. Le halo `shadow-indigo-500/30` disparait avec
                 l'indigo : une ombre teintee en dur aurait survecu au changement de theme. --}}
            x-bind:class="(hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})
                ? 'scale-100 text-white shadow-sm hover:opacity-90'
                : 'scale-95 bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'"
            x-bind:style="(hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})
                ? 'background-color: var(--bp-primary)'
                : ''"
            {{-- TASK-1684 — deux gardes, deux portees distinctes :
                 `uploading` (Alpine) ferme la fenetre entre le choix du fichier
                 et son arrivee au serveur ; `wire:loading` ferme le double-appui
                 pendant que l'envoi est en vol — sur un reseau mobile lent, rien
                 ne bougeait a l'ecran et le second appui partait avec un
                 composeur deja vide, ce qui produisait LE MEME message faux.
                 `wire:loading` retire l'attribut de lui-meme a la fin de la
                 requete, succes COMME echec : aucun risque de bouton bloque. --}}
            x-bind:disabled="uploading"
            wire:loading.attr="disabled"
            wire:target="sendMessage"
            @if($disabled || $loading) disabled @endif
        >
            @if($loading)
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            @else
                {{-- TASK-1684 — un bouton desactive sans explication se lit comme
                     une panne. Le `style` inline tient AVANT le demarrage d'Alpine :
                     sans lui, le sablier clignote au premier rendu. --}}
                <svg x-show="uploading" style="display: none" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg x-show="!uploading" class="{{ isset($leading) ? 'h-5 w-5 md:h-4 md:w-4' : 'w-4 h-4' }} rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/>
                </svg>
            @endif
        </button>

        @if($canMentionAll)
        {{-- TASK-1797 — la confirmation avant une mention collective.
             Positionnee EN FIXED, donc indifferente a sa place dans le DOM :
             elle reste a l'interieur du `<form>` pour partager le MEME
             `x-data` (`confirmTousOuvert`, `mentionables`, `$wire`) plutot
             que d'en ouvrir un second qui devrait les dupliquer. Les deux
             boutons sont `type="button"` : aucun des deux ne doit pouvoir
             soumettre ce formulaire. --}}
        <div data-mention-all-confirm
             x-show="confirmTousOuvert"
             x-cloak
             x-on:keydown.escape.window="confirmTousOuvert = false"
             class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-gray-800">
                <p class="text-sm leading-6 text-gray-800 dark:text-gray-100"
                   x-text="@js(__('loops.mention_all_confirm_body', ['count' => '__COUNT__'])).replace('__COUNT__', mentionables.length)"></p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button"
                            data-mention-all-cancel
                            x-on:click="confirmTousOuvert = false"
                            class="rounded-full px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('loops.mention_all_confirm_cancel') }}
                    </button>
                    <button type="button"
                            data-mention-all-send
                            x-on:click="confirmTousOuvert = false; $wire.sendMessage()"
                            class="rounded-full px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
                            style="background-color: var(--bp-primary)">
                        {{ __('loops.mention_all_confirm_send') }}
                    </button>
                </div>
            </div>
        </div>
        @endif
    </form>

    @if($error)
        <p class="text-xs text-red-500 mt-2">{{ $error }}</p>
    @endif

    @if($slot)
        {{ $slot }}
    @endif
</div>
