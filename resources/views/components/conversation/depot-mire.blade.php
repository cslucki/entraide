{{-- ========================================================================
     TASK-1772 (recette Cyril, 06/11) — LA MIRE DE TELEVERSEMENT DU TELEPHONE.
     ========================================================================
     « La mire apparait sur Desktop, mais pas sur smartphone. Il faudrait un
     composant popup Alpine dedie, qui fait une mire de televersement premium
     sur smartphone. »

     Pourquoi le bandeau d'avant ne se voyait pas, alors qu'il EXISTAIT (mesure
     au navigateur : il s'affiche bien) — deux raisons qui se cumulent :

     1. **Il durait quelques dizaines de millisecondes.** Un PDF de 500 Ko part
        en un battement de cil vers un serveur proche. L'indicateur apparaissait
        et disparaissait entre deux images. Un retour qu'on ne peut pas voir
        n'est pas un retour : cette mire tient donc un PLANCHER de temps
        d'affichage, et montre toujours un etat d'arrivee.
     2. **Il vivait a `bottom-24`**, dans la zone que le composeur, la barre
        d'onglets et la zone sure d'un telephone se disputent. Celle-ci couvre
        l'ecran : on ne peut pas la rater.

     Elle ecoute les evenements que TOUTE surface de depot emet deja
     (`bp-depot-progression`, `bp-depot-fichier`) : la tuile du composeur comme
     la Card Dossiers. Aucune des deux n'a besoin de la connaitre.

     `md:hidden` : au bureau, la page Dossiers et la Card ont leur propre
     progression, a l'endroit ou le geste a eu lieu. Deux mires pour un meme
     envoi seraient une de trop. --}}
<div
    x-data="{
        ouvert: false,
        termine: false,
        ok: false,
        pourcent: 0,
        phase: 'envoi',
        /* Vrai des qu'une progression MESURABLE est arrivee (1..98). Sur une
           liaison rapide il n'en arrive aucune — voir `bpTeleverserFichiers`.
           On montre alors une attente indeterminee plutot qu'un 0 % fige, qui
           donne a croire que rien ne part. */
        mesurable: false,
        nom: '',
        nombre: 0,
        raison: '',
        debut: 0,
        minuteur: null,

        /* Le PLANCHER : 900 ms d'affichage au minimum. Sans lui, un envoi
           rapide ne laisse aucune trace a l'oeil — c'est tout le defaut
           rapporte. */
        demarrer(detail) {
            clearTimeout(this.minuteur);
            this.ouvert = true;
            this.termine = false;
            this.ok = false;
            this.raison = '';
            this.pourcent = detail.pourcent ?? 0;
            this.phase = detail.phase ?? 'envoi';
            this.mesurable = false;
            this.nom = detail.nom ?? '';
            this.nombre = detail.nombre ?? 1;
            this.debut = Date.now();
        },

        avancer(detail) {
            if (! this.ouvert) { this.demarrer(detail); return; }
            this.pourcent = detail.pourcent ?? this.pourcent;
            this.phase = detail.phase ?? this.phase;
            if (this.phase === 'envoi' && this.pourcent > 0 && this.pourcent < 100) {
                this.mesurable = true;
            }
            if (detail.nom) { this.nom = detail.nom; }
        },

        conclure(detail) {
            if (! this.ouvert) { return; }

            const reste = Math.max(0, 900 - (Date.now() - this.debut));

            this.minuteur = setTimeout(() => {
                this.ok = !! detail.ok;
                this.raison = detail.raison || '';
                this.pourcent = detail.ok ? 100 : this.pourcent;
                this.termine = true;
                /* L'etat d'arrivee reste lisible : une coche qui clignote ne
                   dit rien de plus qu'une absence de coche. */
                this.minuteur = setTimeout(() => { this.ouvert = false; }, detail.ok ? 1400 : 3500);
            }, reste);
        },
    }"
    x-on:bp-depot-progression.window="avancer($event.detail)"
    x-on:bp-depot-fichier.window="conclure($event.detail)"
    class="md:hidden"
>
    <template x-teleport="body">
        <div x-show="ouvert"
             x-cloak
             data-depot-mire
             x-transition.opacity
             class="fixed inset-0 z-[60] flex items-center justify-center bg-black/55 px-8 backdrop-blur-sm"
             role="status"
             aria-live="polite">
            <div class="w-full max-w-[17rem] rounded-3xl bg-white p-6 text-center shadow-2xl dark:bg-gray-800">
                {{-- L'anneau de progression. Un cercle SVG plutot qu'une barre :
                     il tient dans la largeur d'un pouce, reste lisible a toute
                     taille d'ecran, et c'est la forme que les messageries
                     mobiles emploient pour un envoi. --}}
                <div class="relative mx-auto h-24 w-24">
                    <svg class="h-24 w-24 -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
                        <circle cx="50" cy="50" r="44" fill="none" stroke="currentColor" stroke-width="8"
                                class="text-gray-200 dark:text-gray-700"></circle>
                        {{-- 276,5 = 2πr arrondi. `stroke-dashoffset` fait le
                             tour : 276,5 = vide, 0 = plein. --}}
                        {{-- Deux anneaux, un seul a la fois :
                             - MESURABLE : l'arc suit le pourcentage ;
                             - sinon : un arc court qui tourne, qui dit « ca
                               travaille » sans pretendre savoir ou ca en est.
                             Un 0 % fige disait le contraire de la verite. --}}
                        <circle x-show="termine || mesurable"
                                cx="50" cy="50" r="44" fill="none" stroke-width="8" stroke-linecap="round"
                                class="transition-all duration-300"
                                x-bind:class="termine ? (ok ? 'text-emerald-500' : 'text-red-500') : 'text-[color:var(--bp-primary)]'"
                                stroke="currentColor"
                                stroke-dasharray="276.5"
                                x-bind:stroke-dashoffset="276.5 - (276.5 * (termine && ok ? 100 : pourcent) / 100)"></circle>
                        <circle x-show="! termine && ! mesurable"
                                cx="50" cy="50" r="44" fill="none" stroke-width="8" stroke-linecap="round"
                                class="origin-center animate-spin text-[color:var(--bp-primary)]"
                                stroke="currentColor"
                                stroke-dasharray="70 206.5"></circle>
                    </svg>

                    <div class="absolute inset-0 flex items-center justify-center">
                        <template x-if="! termine && mesurable">
                            <span class="text-xl font-bold tabular-nums text-gray-900 dark:text-gray-100" x-text="pourcent + '%'"></span>
                        </template>
                        <template x-if="termine && ok">
                            <svg class="h-10 w-10 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                        </template>
                        <template x-if="termine && ! ok">
                            <svg class="h-10 w-10 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </template>
                    </div>
                </div>

                <p class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100"
                   x-text="termine
                        ? (ok ? @js(__('loops.composer_file_uploaded')) : @js(__('loops.composer_file_failed')))
                        : (phase === 'traitement'
                            ? @js(__('loops.composer_file_processing'))
                            : (nombre > 1
                                ? @js(__('loops.composer_file_uploading_many')).replace(':count', nombre)
                                : @js(__('dossiers.file_uploading'))))"></p>

                {{-- Le nom du fichier pendant l'envoi, la raison du refus
                     apres : a chaque instant, la ligne du bas dit ce qu'on a
                     besoin de savoir, et rien d'autre. --}}
                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400"
                   x-show="(! termine && nom) || (termine && ! ok && raison)"
                   x-text="termine ? raison : nom"></p>
            </div>
        </div>
    </template>
</div>
