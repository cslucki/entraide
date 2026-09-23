<?php

/**
 * TASK-1626 — LA source unique des icones de navigation.
 *
 * ROOT_CAUSE de la divergence : il n'y avait pas de source. Le rail desktop
 * (`components/app-side-nav.blade.php`) et la barre mobile
 * (`components/mobile-bottom-nav.blade.php`) portaient chacun sa propre table
 * de traces SVG, en chaines `d` recopiees a la main. Deux tables independantes
 * pour une meme grammaire : la divergence n'etait pas un accident, c'etait
 * l'etat par defaut. Elle avait deja produit trois ecarts — Boucles, Flux et
 * Annuaire — dont personne n'avait decide.
 *
 * Ce fichier ne cree pas d'abstraction : c'est un tableau plat, lu par les
 * deux vues. Il rend simplement IMPOSSIBLE de changer une icone d'un cote
 * sans l'autre.
 *
 * Contrainte de taille, MESUREE et non supposee : le rail desktop rend en
 * `h-4 w-4` — 16 px — quand la barre mobile rend en 24 px. Un trace doit donc
 * tenir a SEIZE pixels avec un trait de 1.8. C'est cette mesure qui a ecarte
 * la rosette du logo pour « Boucles » : a 16 px, huit cercles en anneau
 * s'effondrent en tache, et aucune simplification testee (6 cercles, 5
 * cercles, anneau a noeuds) n'a retrouve la nettete de la bulle.
 *
 * Grammaire commune : `viewBox="0 0 24 24"`, `fill="none"`,
 * `stroke="currentColor"`, `stroke-width="1.8"`, extremites et jointures
 * arrondies. Un trace qui demanderait une autre epaisseur n'appartient pas a
 * cette barre.
 */
return [

    // Boucles — la bulle ronde. Deux lignes dans un cercle : un echange qui
    // se tient dans un espace clos. Sa silhouette RONDE la distingue de
    // « Messagerie », dont la bulle est rectangulaire et porte une queue.
    'loops' => 'M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z',

    // Flux — des lignes qui defilent, et une fleche qui avance. La barre
    // mobile montrait un DOSSIER, c'est-a-dire l'icone de « Dossiers » : deux
    // destinations differentes portaient le meme signe.
    'feed' => 'M4 5h16M4 12h10M4 19h16M18 9l3 3-3 3',

    // Agenda — le calendrier. Deja identique des deux cotes.
    'agenda' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0V11.25A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',

    // Echanges — deux fleches en sens inverse. Deja identique.
    'exchanges' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4',

    // Messagerie — la bulle rectangulaire a queue. Deja identique.
    'messaging' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z',

    // Annuaire — UN GROUPE DE PERSONNES.
    //
    // Les deux cotes montraient un DIAGRAMME A BARRES, et pas meme le meme :
    // des barres ne disent ni membre, ni contact, ni repertoire. Ce trace est
    // celui que le depot utilise deja pour « Membres » dans
    // `components/loops/card-icon.blade.php` — rien n'est invente, rien n'est
    // ajoute. Mesure faite a 16, 20 et 24 px : les trois silhouettes restent
    // distinctes et le groupe se lit immediatement.
    'directory' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',

    // Blog — le journal. Deja identique.
    'blog' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2M7 8h6M7 12h6M7 16h4',

    // Dossiers — la chemise. Deja identique.
    'my_dossiers' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',

    // Notifications — la cloche. Portee par le rail ET par le header mobile
    // depuis TASK-1625 ; elle entre ici pour la meme raison que les autres.
    'notifications' => 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
];
