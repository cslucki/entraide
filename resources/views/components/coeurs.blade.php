{{-- ============================================================================
     LA PLUIE DE COEURS — composant unique, extrait de TASK-1773.
     ============================================================================
     TASK-1808 §4 demande une pluie de coeurs pour l'aidant a sa premiere venue
     apres la cloture, et impose de « reutiliser l'animation existante » plutot
     que d'en developper une seconde. L'animation vivait en ligne dans
     `services/created.blade.php` : elle est deplacee ICI telle quelle, et cette
     page l'appelle maintenant comme tout le monde.

     Les regles visuelles restent dans `app.css` (`.bp-coeurs`, `.bp-coeur`),
     y compris le traitement de `prefers-reduced-motion` : une personne qui a
     demande moins de mouvement voit les coeurs apparaitre et s'effacer, sans
     montee.

     Deux proprietes tenues par la forme meme :

     - **non bloquante** : le calque est en `position: fixed` avec
       `pointer-events: none`, il ne participe pas au flux et n'avale aucun
       clic. Il disparait quand la derniere animation est finie — un element
       fixe qui reste, meme invisible, continue de couvrir la page pour les
       outils qui la lisent ;
     - **une seule lecture par page** : `window.__bpCoeursJoues` empeche une
       seconde pluie quand le navigateur restitue la page depuis son cache.

     Le « une seule fois PAR ENTRAIDE ET PAR AIDANT » du §4, lui, ne peut pas se
     tenir ici : ce drapeau vit en memoire de page et ne survit ni a un
     rechargement ni a un autre appareil. C'est `transactions.seller_celebrated_at`
     qui le porte, cote serveur, et qui decide si ce composant est rendu.

     TASK-1808 (recette navigateur, 11/11) — LE DRAPEAU DOIT ETRE SCOPE.
     Mesure en conditions reelles : ce fil utilise `wire:poll.3000ms`, qui
     relit `pluieDeCoeurs` toutes les trois secondes SANS rechargement de
     page — sans un drapeau quelconque, la pluie rejouerait indefiniment
     toutes les trois secondes sur une seule visite. Le drapeau d'ORIGINE
     (`window.__bpCoeursJoues`, un booleen global) tenait cette premiere
     exigence, mais en cassait une seconde : BouclePro navigue par
     `wire:navigate`, qui PRESERVE le contexte JS d'une page a l'autre. Un
     booleen global partage donc son « deja joue » entre DEUX ENTRAIDES
     DIFFERENTES visitees dans le meme onglet — la seconde ne recevrait
     jamais sa pluie, meme legitimement due. Mesure en recette : la pluie ne
     se rejouait plus apres une premiere lecture, y compris sur une AUTRE
     transaction fraichement remise a zero cote serveur.

     Le drapeau devient donc un ENSEMBLE de cles, et chaque appelant fournit
     la sienne (`cle`) : la MEME cle protege toujours contre le double jeu
     d'un `wire:poll` ou d'un retour en arriere sur la MEME page ; deux cles
     DIFFERENTES (deux entraides, par ex.) ne se bloquent plus l'une
     l'autre.

     TASK-1808 (recette navigateur, 11/11) — ET MEME DEUX COPIES DE LA MEME
     CLE PEUVENT SE DISPUTER LA PLUIE. `messages/index.blade.php` rend DEUX
     fois ce composant pour la MEME entraide — une copie mobile `md:hidden`,
     une copie desktop `hidden md:flex` — donc avec la MEME `cle`. Sans
     garde supplementaire, le PREMIER `init()` a s'executer (l'ordre du DOM,
     presque toujours la copie mobile, codee en premier) consomme la cle et
     peuple SES propres coeurs — meme si cette copie est celle que la CSS
     cache. La copie VISIBLE, EUE, trouve la cle deja prise et n'affiche
     rien : la pluie existe bien dans le DOM, mais dans la moitie que
     personne ne regarde.

     `getClientRects().length === 0` distingue les deux — un element dont un
     ANCETRE est `display:none` rend une liste de rectangles VIDE, meme si
     l'element lui-meme est `position: fixed` (son propre `display` calcule
     resterait pourtant « block » — ce n'est PAS un signal fiable, seul le
     rectangle de rendu reel l'est). La copie non rendue sort SANS consommer
     la cle, laissant l'autre la reclamer.

     MAIS pas sur `this.$el` LUI-MEME : mesure en recette, son rectangle est
     TOUJOURS vide, visible ou non — ce composant porte `x-show="coeurs.length
     > 0"`, et `coeurs` est PRECISEMENT encore vide au moment de decider s'il
     faut la peupler. `x-show` masque donc l'element par construction, pour
     les DEUX copies, independamment de tout ancetre responsive — le signal
     que je cherchais s'y noie dans celui, sans rapport, que je viens moi-meme
     de poser.
     C'est le PARENT (le Bento, un simple `<div>` sans `x-show` propre, dont
     la visibilite ne depend QUE des ancetres) qui porte le signal fiable.
     `$nextTick()` reste necessaire : laisser Alpine finir d'appliquer les
     directives de ce premier cycle avant de lire quoi que ce soit. --}}
@props(['marqueur' => 'data-coeurs', 'cle' => 'defaut'])
<div x-data="{
        coeurs: [],
        init() {
            this.$nextTick(() => this.peuplerSiVisible());
        },
        peuplerSiVisible() {
            // Cette copie ne sera de toute facon pas vue : ne pas lui
            // laisser consommer la cle a la place de celle qui compte.
            // Lu sur le PARENT, pas sur `this.$el` (voir le docbloc du
            // composant) : `this.$el` porte son propre `x-show`, toujours
            // faux a cet instant puisque rien n'est encore peuple.
            if (this.$el.parentElement.getClientRects().length === 0) { return; }

            window.__bpCoeursJoues = window.__bpCoeursJoues || new Set();
            if (window.__bpCoeursJoues.has('{{ $cle }}')) { return; }
            window.__bpCoeursJoues.add('{{ $cle }}');

            this.coeurs = Array.from({ length: 24 }, (_, i) => ({
                id: i,
                /* QUE DES COEURS (arbitrage Cyril, TASK-1773) : un seul
                   symbole decline en couleurs dit l'elan sans ressembler a une
                   barre de reactions. La repartition suit l'ordre de la liste
                   (`i % 5`) et non un tirage — un tirage laisse parfois six
                   coeurs identiques d'affilee. */
                glyphe: ['❤️', '💜', '💚', '🧡', '💛'][i % 5],
                gauche: Math.round(Math.random() * 92) + 2,
                taille: (Math.random() * 1.4 + 1).toFixed(2),
                retard: (Math.random() * 1.8).toFixed(2),
                duree: (Math.random() * 2 + 3.8).toFixed(2),
                derive: Math.round((Math.random() - 0.5) * 160),
                tour: Math.round((Math.random() - 0.5) * 90),
            }));

            setTimeout(() => { this.coeurs = []; }, 7000);
        },
     }"
     x-show="coeurs.length > 0"
     x-cloak
     class="bp-coeurs"
     aria-hidden="true"
     {{ $marqueur }}>
    <template x-for="coeur in coeurs" :key="coeur.id">
        <span class="bp-coeur"
              x-text="coeur.glyphe"
              :style="`left: ${coeur.gauche}%; font-size: ${coeur.taille}rem; --bp-coeur-retard: ${coeur.retard}s; --bp-coeur-duree: ${coeur.duree}s; --bp-coeur-derive: ${coeur.derive}px; --bp-coeur-tour: ${coeur.tour}deg;`"></span>
    </template>
</div>
