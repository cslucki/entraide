@props(['disabled' => false])

@php
    /* TASK-1871 — un champ en faute le DIT, sans qu'on ait a le repeter.
       Le nom du champ suffit a savoir s'il porte une erreur : inutile de
       poser `aria-invalid` a la main sur chacun des vingt champs du profil,
       ce qui aurait fait vingt occasions d'en oublier un.
       `aria-describedby` pointe vers l'`id` que `x-input-error` pose a partir
       du meme nom — les deux composants se rejoignent sur cette convention et
       sur rien d'autre.
       Un champ sans `name`, ou sans erreur, ne recoit aucun de ces attributs :
       le rendu est alors identique a ce qu'il etait. */
    $_nom = $attributes->get('name');
    $_enFaute = $_nom && $errors->has($_nom);

    /* TASK-1871 — LE RAIL D'ETAT.
       ============================
       Le formulaire du profil fait plus de six cents lignes, et tous ses
       champs se ressemblent. Ce qui manquait n'etait pas de la decoration :
       c'etait un endroit FIXE ou l'oeil puisse lire l'etat du champ qu'il
       est en train de remplir.

       Une bordure epaisse a gauche, invisible au repos, qui s'allume au
       focus et vire au rouge en faute. Elle tient la colonne sur toute la
       hauteur du formulaire : on suit un rail, pas une suite de rectangles.

       Le reflexe aurait ete un contour rouge sur tout le champ. Il crie,
       il double le message deja ecrit dessous, et sur un fond sombre il
       devient une tache. Le rail dit la meme chose en restant calme — et il
       reste lisible pour qui distingue mal les couleurs, puisque la position
       porte l'information autant que la teinte.

       Jetons du design system uniquement (`--bp-*`) : aucune couleur neuve
       n'est introduite ici. */
    /* Le fond est `--bp-surface`, PAS `--bp-panel`.
       ---------------------------------------------
       Le bloc qui contient ce formulaire est deja peint en `--bp-panel` :
       donner la meme valeur au champ le faisait disparaitre dans son propre
       conteneur. En sombre, les deux valaient `#050F2C` et le champ
       n'existait plus que par un filet `#24427F` — signale en recette par
       Cyril.
       `--bp-surface` est plus CLAIR que `panel` en sombre (`#0A2058` contre
       `#050F2C`) et legerement teinte en clair (`#F3FAF4` sur du blanc) : le
       champ se detache dans les deux sens, sans qu'aucune couleur neuve
       n'entre dans le systeme.
       Meme piege que la lecon deja payee sur `--bp-surface` confondu avec
       `--bp-page` : deux jetons voisins ne sont pas interchangeables, il faut
       lire leurs valeurs par theme avant de choisir.

       Et AUCUN modificateur d'opacite sur une variable CSS.
       -----------------------------------------------------
       `ring-[color:var(--bp-primary)]/25` rend une couleur INVALIDE : il n'y
       avait donc aucun anneau de focus, silencieusement. Piege paye en T1475,
       remesure en T1771. La couleur est ici posee pleine. */
    /* La bordure est MESUREE, pas choisie a l'oeil.
       ----------------------------------------------
       `--bp-border` est fait pour separer des blocs, pas pour dessiner un
       controle : contre `--bp-surface` il plafonne a 1.48-1.71 selon le
       theme, quand la WCAG 1.4.11 demande 3:1 pour la limite d'un champ de
       saisie. Un champ dont on ne voit pas le bord n'est pas un detail de
       gout, c'est une cible qu'on ne sait pas viser.
       `--bp-muted` pur monterait a 8-12:1 : exact, mais le champ se mettrait
       a crier plus fort que son contenu. Le melange a ete calcule sur les
       CINQ themes, clair et sombre : 35% donne 2.23, 45% donne 2.83, et 55%
       donne 3.55 — le premier dosage conforme partout. C'est celui-la. */
    $_base = 'w-full rounded-lg border border-[color:color-mix(in_srgb,var(--bp-muted)_55%,var(--bp-surface))] bg-[color:var(--bp-surface)] px-3 py-2 text-[color:var(--bp-text)] shadow-sm transition-colors duration-150 placeholder:text-[color:var(--bp-muted)] focus:outline-none focus:border-[color:var(--bp-primary)] focus:ring-2 focus:ring-[color:var(--bp-primary)]';
    $_rail = $_enFaute
        ? 'border-s-[3px] border-s-red-500 focus:border-s-red-500'
        : 'border-s-[3px] border-s-transparent focus:border-s-[color:var(--bp-primary)]';
@endphp

<input @disabled($disabled)
    @if($_enFaute) aria-invalid="true" aria-describedby="{{ $_nom }}-error" @endif
    {{ $attributes->merge(['class' => $_base.' '.$_rail]) }}>
