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
     qui le porte, cote serveur, et qui decide si ce composant est rendu. --}}
@props(['marqueur' => 'data-coeurs'])
<div x-data="{
        coeurs: [],
        init() {
            if (window.__bpCoeursJoues) { return; }
            window.__bpCoeursJoues = true;

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
