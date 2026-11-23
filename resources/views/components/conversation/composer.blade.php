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
    // TASK-1886 — le MESSAGE VOCAL, OPT-IN. Seule la Messagerie privee le
    // passe : c'est le seul serveur qui sait recevoir un vocal (`$voice`) et
    // le faire expirer. Le ChatLoop et le chat agent gardent leur bouton
    // d'envoi a l'octet. Meme discipline que `uploadAccept` et `mentionables`.
    'voice' => false,
    'voiceMaxSeconds' => 300,
])

@php
    $placeholder ??= __('messages.write_message');
    $uploadLabel ??= __('messages.send_image');
@endphp

{{-- Le gabarit compact mobile est SCOPE au ChatLoop (seul appelant qui
     fournit `$leading`) : Message Thread et le chat agent gardent leur
     gabarit historique a l'identique. --}}
{{-- TASK-1886 — l'enregistreur vit sur la RACINE, au-dessus du `<form>` :
     ses etats (`etat`, `vocalActif`) sont lus par le formulaire, dont la
     portee Alpine herite. Sans `voice`, la racine reste celle d'avant. --}}
<div class="border-t border-gray-200 dark:border-gray-700 {{ isset($leading) ? 'px-3 py-2.5 md:px-5 md:py-4' : 'px-5 py-4' }}"
    @if($voice)
    x-data="voiceRecorder({
        maxSeconds: {{ (int) $voiceMaxSeconds }},
        i18n: {
            denied: @js(__('messages.voice_mic_denied')),
            unavailable: @js(__('messages.voice_mic_unavailable')),
            unsupported: @js(__('messages.voice_unsupported')),
            failed: @js(__('messages.voice_send_failed')),
        },
    })"
    data-composer-voice
    @endif
>
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
    {{-- TASK-1886 (copie conforme WhatsApp, captures Cyril 23/11) — avec
         `voice`, le FORMULAIRE est la pilule : trombone, champ, micro ou
         envoi vivent DANS le meme cadre, et l'enregistrement s'y deroule
         aussi. Sans `voice`, le gabarit d'avant, a l'octet. --}}
    <form wire:submit="sendMessage" class="{{ $voice
        ? 'flex items-end gap-0.5 rounded-[26px] border border-gray-300 bg-white p-1 shadow-sm transition focus-within:border-[color:var(--bp-primary)] dark:border-gray-600 dark:bg-gray-900'
        : 'flex items-end '.(isset($leading) ? 'gap-2 md:gap-3' : 'gap-3') }}"
        {{-- TASK-1872 correctif 06 — emis par `LoopChat` quand repondre pose
             une mention. Borne aux appelants qui ont des mentionnables, donc
             au seul ChatLoop : la Messagerie et le chat agent n'ont pas de
             menu `@` et ne doivent pas gagner un ecouteur. --}}
        @if($mentionables !== [])
        x-on:bp-mention-auto.window="mentionAutoSynchroniser($event.detail)"
        @endif
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

            /* TASK-1872 correctif 06 — garder la liste d'identifiants en
               phase avec la mention que le SERVEUR vient de poser.
               ================================================================
               Repondre a quelqu'un ecrit « @Son Nom : » dans le composeur, de
               sorte que la notification parte par le mecanisme de mention qui
               existe deja — aucune seconde plomberie de notification.

               Le texte, c'est le serveur qui l'ecrit (`body` est le modele).
               Mais la liste d'identifiants, elle, vit ICI : `mentionChoisir()`
               la REVERSE telle quelle a chaque choix. Sans cette
               synchronisation, la premiere mention ajoutee a la main
               effacerait celle de la reponse — le texte resterait a l'ecran,
               la notification ne partirait pas, et rien ne le dirait.

               `ancien` retire la mention d'une cible precedente : repondre a
               A puis a B sans rien ecrire ne doit pas notifier A, qui n'y
               est pour rien. `id` nul = la reponse a ete annulee. */
            mentionAutoSynchroniser(detail) {
                const ancien = detail?.ancien ?? null;
                const neuf = detail?.id ?? null;

                if (ancien) {
                    this.mentionsChoisies = this.mentionsChoisies.filter((x) => x !== ancien);
                }

                if (neuf && ! this.mentionsChoisies.includes(neuf)) {
                    this.mentionsChoisies.push(neuf);
                }
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
               @if($voice) x-show="! vocalActif" @endif
               aria-label="{{ $uploadLabel }}"
               title="{{ $uploadLabel }}"
               @if($voice)
               class="{{ isset($leading) ? 'hidden md:flex' : 'flex' }} h-10 w-10 flex-shrink-0 cursor-pointer items-center justify-center rounded-full text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white">
            {{-- TASK-1886 — le trombone de WhatsApp : ce bouton joint TOUT
                 (images, documents, audio, video), pas seulement une image. --}}
            <svg class="h-5 w-5 -rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m18.4 10.6-7.07 7.07a4.5 4.5 0 0 1-6.36-6.36l7.78-7.78a3 3 0 0 1 4.24 4.24l-7.43 7.43a1.5 1.5 0 0 1-2.12-2.12l6.72-6.72"/>
            </svg>
               @else
               class="{{ isset($leading) ? 'hidden md:flex' : 'flex' }} mb-1 flex-shrink-0 w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-500 dark:text-gray-400 items-center justify-center cursor-pointer transition disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
               @endif
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
             `$leading`).
             TASK-1886 (capture Cyril, 23/11) — `md:dark:bg-transparent`.
             En mode sombre, `dark:bg-gray-900` se compile en
             `.dark .dark\:bg-gray-900` (deux classes) et battait
             `md:bg-transparent` (une seule) : au bureau, ce conteneur gardait
             son fond, et ses angles DROITS (`md:rounded-none`) depassaient en
             noir derriere le champ arrondi. La variante combinee a la meme
             specificite que `dark:` et vient apres : elle gagne. --}}
        <div @if($voice) x-show="! vocalActif" @endif class="relative min-w-0 flex-1 {{ $voice
            ? 'flex items-end gap-1'
            : (isset($leading)
            ? 'flex items-end gap-1 rounded-[23px] border border-gray-300 bg-white px-[5px] py-[4px] shadow-sm transition focus-within:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 md:block md:rounded-none md:border-0 md:bg-transparent md:p-0 md:shadow-none md:focus-within:border-transparent md:dark:bg-transparent'
            : '') }}">
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
                class="{{ $voice
                    ? 'block max-h-44 min-h-10 w-full resize-none overflow-y-hidden border-0 bg-transparent px-2 py-2.5 text-[15px] leading-5 text-gray-900 placeholder-gray-400 shadow-none [scrollbar-width:none] focus:outline-none focus:ring-0 dark:text-gray-100 md:text-sm [&::-webkit-scrollbar]:hidden'
                    : (isset($leading)
                    ? 'block max-h-44 min-h-9 w-full resize-none overflow-y-hidden border-0 bg-transparent px-1 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-none transition [scrollbar-width:none] focus:outline-none focus:ring-0 dark:text-gray-100 [&::-webkit-scrollbar]:hidden md:min-h-11 md:rounded-2xl md:border md:border-gray-300 md:bg-white md:px-4 md:py-3 md:shadow-sm md:focus:border-indigo-500 md:focus:ring-2 md:focus:ring-indigo-500/20 md:dark:border-gray-600 md:dark:bg-gray-900'
                    : 'block max-h-44 min-h-11 w-full resize-none overflow-y-hidden rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition [scrollbar-width:none] focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 [&::-webkit-scrollbar]:hidden') }}"
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

        @if($voice)
        {{-- ================================================================
             TASK-1886 — LA BARRE D'ENREGISTREMENT, facon WhatsApp Web
             (captures Cyril, 23/11).
             ================================================================
             Elle prend la place EXACTE du champ (meme pilule, meme hauteur) :
             rien ne saute quand on appuie sur le micro. Ses commandes se
             rangent A DROITE, pres du pouce et du bouton envoyer :

               enregistre : 🗑  ● 0:02  ┄┄▎▍▌┄  ⏸        (➤)
               pause      : 🗑  ▶  ●━━▍▌▎┄┄┄  0:28  🎤   (➤)
               envoi      :        0:28  ┄▍▌▎┄  Envoi…   (◔) ← la mire

             La pause SUSPEND : on reecoute, puis le micro rouge reprend a la
             suite, dans le meme vocal. --}}
        <div x-show="vocalActif"
             x-cloak
             data-voice-recorder
             role="status"
             aria-live="polite"
             class="flex min-h-10 min-w-0 flex-1 items-center justify-end gap-1.5 pl-1 sm:gap-2">

            <span x-show="etat === 'demande'" class="mr-auto min-w-0 truncate pl-2 text-sm text-gray-500 dark:text-gray-400">{{ __('messages.voice_mic_asking') }}</span>
            <span x-show="etat === 'erreur'" x-text="erreur" data-voice-error class="mr-auto min-w-0 pl-2 text-sm text-red-600 dark:text-red-400"></span>
            <span x-show="etat === 'envoye'" class="mr-auto flex min-w-0 items-center gap-2 pl-2 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                {{ __('messages.voice_sent') }}
            </span>

            {{-- Corbeille : abandonne sans rien envoyer. --}}
            <button type="button"
                    x-show="etat === 'enregistre' || etat === 'pause' || etat === 'demande'"
                    x-on:click="annuler()"
                    data-voice-cancel
                    aria-label="{{ __('messages.voice_cancel') }}"
                    title="{{ __('messages.voice_cancel') }}"
                    class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-gray-600 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40 dark:text-gray-300 dark:hover:bg-red-900/30 dark:hover:text-red-400">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.35 9m-4.78 0L9.26 9m9.97-3.21c.34.05.68.1 1.02.16m-1.02-.16L18.16 19.67A2.25 2.25 0 0 1 15.92 21.75H8.08a2.25 2.25 0 0 1-2.24-2.08L4.77 5.79m14.46 0a48.1 48.1 0 0 0-3.48-.4m-12 .56c.34-.06.68-.11 1.02-.16m0 0a48.1 48.1 0 0 1 3.48-.4m7.5 0v-.92c0-1.18-.91-2.16-2.09-2.2a51.96 51.96 0 0 0-3.32 0c-1.18.04-2.09 1.02-2.09 2.2v.92m7.5 0a48.67 48.67 0 0 0-7.5 0"/></svg>
            </button>

            {{-- ── ENREGISTREMENT ─────────────────────────────────────── --}}
            <template x-if="etat === 'enregistre' || etat === 'envoi'">
                <div class="flex min-w-0 items-center gap-1.5 sm:gap-2">
                    {{-- Le point qui respire : on SAIT que le micro ecoute. --}}
                    <span x-show="etat === 'enregistre'" class="relative ml-1 flex h-2.5 w-2.5 flex-shrink-0" aria-hidden="true">
                        <span class="absolute inset-0 rounded-full bg-[#E5484D] opacity-60 motion-safe:animate-ping"></span>
                        <span class="relative h-2.5 w-2.5 rounded-full bg-[#E5484D]"></span>
                    </span>
                    <span x-text="dureeLisible" data-voice-timer class="w-10 flex-shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100"></span>
                    {{-- La voix en direct : les barres recentes entrent a
                         droite et poussent les anciennes. Figee et adoucie
                         pendant l'envoi, elle devient le fond de la mire. --}}
                    <div aria-hidden="true"
                         class="flex h-6 w-24 flex-shrink-0 items-center justify-end gap-[2px] overflow-hidden transition-opacity duration-300 sm:w-32"
                         x-bind:class="etat === 'envoi' ? 'opacity-35' : ''">
                        <template x-for="(hauteur, index) in direct" :key="index">
                            <span class="block w-[2px] flex-shrink-0 rounded-full bg-gray-500 dark:bg-gray-400"
                                  x-bind:style="`height: ${hauteur}%`"></span>
                        </template>
                    </div>
                    <span x-show="etat === 'envoi'" data-voice-sending class="flex-shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('messages.voice_sending') }}</span>
                    {{-- PAUSE, en rouge comme le point : c'est l'enregistrement
                         qu'on suspend. --}}
                    <button type="button"
                            x-show="etat === 'enregistre'"
                            x-on:click="suspendre()"
                            data-voice-pause
                            aria-label="{{ __('messages.voice_pause_recording') }}"
                            title="{{ __('messages.voice_pause_recording') }}"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-[#E5484D] transition hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40 dark:hover:bg-red-900/30">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6.5" y="5" width="3.5" height="14" rx="1.2"/><rect x="14" y="5" width="3.5" height="14" rx="1.2"/></svg>
                    </button>
                </div>
            </template>

            {{-- ── PAUSE : ECOUTER AVANT D'ENVOYER ───────────────────── --}}
            <template x-if="etat === 'pause'">
                <div class="flex min-w-0 items-center gap-1.5 sm:gap-2">
                    <button type="button"
                            x-on:click="basculerLApercu()"
                            data-voice-preview
                            x-bind:aria-label="apercuLecture ? @js(__('messages.voice_pause')) : @js(__('messages.voice_play'))"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-gray-800 transition hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--bp-primary)] dark:text-gray-100 dark:hover:bg-gray-800">
                        <svg x-show="! apercuLecture" class="ml-0.5 h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4.9v14.2a1 1 0 0 0 1.5.86l11.6-7.1a1 1 0 0 0 0-1.72L8.5 4.04A1 1 0 0 0 7 4.9Z"/></svg>
                        <svg x-show="apercuLecture" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="4.5" width="4" height="15" rx="1.3"/><rect x="14" y="4.5" width="4" height="15" rx="1.3"/></svg>
                    </button>
                    <div x-on:click="chercherDansLApercu($event)"
                         aria-hidden="true"
                         class="relative flex h-6 w-24 flex-shrink-0 cursor-pointer items-center gap-[2px] sm:w-36">
                        <template x-for="(hauteur, index) in apercuBarres" :key="index">
                            <span class="block min-w-[2px] flex-1 rounded-full"
                                  x-bind:class="apercuBarreJouee(index) ? 'bg-gray-800 dark:bg-gray-100' : 'bg-gray-300 dark:bg-gray-600'"
                                  x-bind:style="`height: ${Math.max(14, hauteur)}%`"></span>
                        </template>
                        <span class="pointer-events-none absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-gray-900 shadow dark:bg-white"
                              x-bind:style="`left: ${apercuRatio * 100}%`"></span>
                    </div>
                    <span x-text="apercuCompteur" class="w-10 flex-shrink-0 text-right text-sm font-medium tabular-nums text-gray-700 dark:text-gray-200"></span>
                    {{-- Le micro ROUGE : reprendre a la suite. --}}
                    <button type="button"
                            x-on:click="reprendre()"
                            data-voice-resume
                            aria-label="{{ __('messages.voice_resume') }}"
                            title="{{ __('messages.voice_resume') }}"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full text-[#E5484D] transition hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/40 dark:hover:bg-red-900/30">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2.75a3.25 3.25 0 0 0-3.25 3.25v5.5a3.25 3.25 0 0 0 6.5 0V6A3.25 3.25 0 0 0 12 2.75Z"/>
                            <path d="M6.5 10.75a.75.75 0 0 0-1.5 0v.75a7 7 0 0 0 6.25 6.96v2.04a.75.75 0 0 0 1.5 0v-2.04A7 7 0 0 0 19 11.5v-.75a.75.75 0 0 0-1.5 0v.75a5.5 5.5 0 0 1-11 0v-.75Z"/>
                        </svg>
                    </button>
                </div>
            </template>
        </div>
        @endif

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
            @if($voice)
            {{-- TASK-1886 — WhatsApp : la fleche n'existe que s'il y a
                 quelque chose a envoyer. A vide, le micro prend sa place. --}}
            x-show="! vocalActif && (hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})"
            @endif
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
            class="{{ $voice ? 'h-10 w-10' : (isset($leading) ? 'mb-0 h-[46px] w-[46px] md:mb-1 md:h-9 md:w-9' : 'mb-1 h-9 w-9') }} flex flex-shrink-0 items-center justify-center rounded-full transition-all duration-200 ease-out active:scale-90 disabled:opacity-50"
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
                @if($voice)
                {{-- TASK-1886 — l'avion en papier de WhatsApp. --}}
                <svg x-show="!uploading" class="h-5 w-5 translate-x-px" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 20.85 12.92a1 1 0 0 0 0-1.84L3.4 3.6a1 1 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91Z"/></svg>
                @else
                <svg x-show="!uploading" class="{{ isset($leading) ? 'h-5 w-5 md:h-4 md:w-4' : 'w-4 h-4' }} rotate-45" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5m0 0l-7 7m7-7l7 7"/>
                </svg>
                @endif
            @endif
        </button>

        @if($voice)
        {{-- TASK-1886 — LE MICRO, a la place exacte de la fleche (memes cotes).
             Comme sur WhatsApp, c'est une simple icone au repos : le disque
             plein de couleur est reserve a l'action qui ENVOIE. Un appui demarre l'enregistrement —
             pas d'appui maintenu : au bureau, une souris ne « maintient » pas
             confortablement, et le geste doit etre le meme partout. --}}
        <button type="button"
                x-show="! vocalActif && ! (hasText || {{ ($showUpload && $photo) ? 'true' : 'false' }})"
                x-on:click="demarrer()"
                data-composer-mic
                aria-label="{{ __('messages.voice_record') }}"
                title="{{ __('messages.voice_record') }}"
                class="h-10 w-10 flex flex-shrink-0 items-center justify-center rounded-full text-gray-600 transition-all duration-200 ease-out hover:bg-gray-100 hover:text-gray-900 active:scale-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--bp-primary)] dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                >
            <svg class="{{ isset($leading) ? 'h-[22px] w-[22px] md:h-[18px] md:w-[18px]' : 'h-[18px] w-[18px]' }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 2.75a3.25 3.25 0 0 0-3.25 3.25v5.5a3.25 3.25 0 0 0 6.5 0V6A3.25 3.25 0 0 0 12 2.75Z"/>
                <path d="M6.5 10.75a.75.75 0 0 0-1.5 0v.75a7 7 0 0 0 6.25 6.96v2.04a.75.75 0 0 0 1.5 0v-2.04A7 7 0 0 0 19 11.5v-.75a.75.75 0 0 0-1.5 0v.75a5.5 5.5 0 0 1-11 0v-.75Z"/>
            </svg>
        </button>

        {{-- TASK-1886 — envoyer le vocal, ET la mire de l'envoi.
             L'anneau autour du bouton se remplit avec la progression REELLE
             de l'upload ; sur une liaison trop rapide pour en mesurer une, un
             arc court tourne (meme regle que `depot-mire` : un 0 % fige dit le
             contraire de la verite). A l'arrivee, la fleche devient une
             coche. --}}
        <button type="button"
                x-show="vocalActif && etat !== 'erreur' && etat !== 'demande'"
                x-cloak
                x-on:click="envoyer()"
                x-bind:disabled="etat !== 'enregistre' && etat !== 'pause'"
                data-voice-send
                aria-label="{{ __('messages.voice_send') }}"
                title="{{ __('messages.voice_send') }}"
                class="h-10 w-10 relative flex flex-shrink-0 items-center justify-center rounded-full text-white shadow-sm transition-all duration-200 ease-out active:scale-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[color:var(--bp-primary)] disabled:cursor-default dark:focus-visible:ring-offset-gray-800"
                x-bind:class="etat === 'envoye' ? 'bg-emerald-500' : ''"
                x-bind:style="etat === 'envoye' ? '' : 'background-color: var(--bp-primary)'">
            <svg x-show="etat === 'envoi'" class="pointer-events-none absolute -inset-[5px] h-[calc(100%+10px)] w-[calc(100%+10px)] -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
                <circle cx="50" cy="50" r="46" fill="none" stroke="currentColor" stroke-width="5" class="text-gray-200 dark:text-gray-700"></circle>
                <circle x-show="progressionMesurable" cx="50" cy="50" r="46" fill="none" stroke-width="5" stroke-linecap="round"
                        class="text-[color:var(--bp-primary)] transition-[stroke-dashoffset] duration-200"
                        stroke="currentColor" stroke-dasharray="289"
                        x-bind:stroke-dashoffset="289 - (289 * progression / 100)"></circle>
                <circle x-show="! progressionMesurable" cx="50" cy="50" r="46" fill="none" stroke-width="5" stroke-linecap="round"
                        class="origin-center text-[color:var(--bp-primary)] motion-safe:animate-spin"
                        stroke="currentColor" stroke-dasharray="70 219"></circle>
            </svg>
            <svg x-show="etat !== 'envoye'" class="h-5 w-5 translate-x-px" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4 20.85 12.92a1 1 0 0 0 0-1.84L3.4 3.6a1 1 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91Z"/></svg>
            <svg x-show="etat === 'envoye'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
        </button>
        @endif

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
