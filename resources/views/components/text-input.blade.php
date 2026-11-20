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
    $_base = 'w-full rounded-lg border border-[color:var(--bp-border)] bg-[color:var(--bp-panel)] px-3 py-2 text-[color:var(--bp-text)] shadow-sm transition-colors duration-150 placeholder:text-[color:var(--bp-muted)] focus:outline-none focus:ring-2 focus:ring-[color:var(--bp-primary)]/25';
    $_rail = $_enFaute
        ? 'border-s-[3px] border-s-red-500 focus:border-s-red-500'
        : 'border-s-[3px] border-s-transparent focus:border-s-[color:var(--bp-primary)]';
@endphp

<input @disabled($disabled)
    @if($_enFaute) aria-invalid="true" aria-describedby="{{ $_nom }}-error" @endif
    {{ $attributes->merge(['class' => $_base.' '.$_rail]) }}>
