@props([
    'disabled' => false,
    /* TASK-1871 — le style enrichi est OPT-IN.
       ========================================
       Ce composant sert aussi la fermeture de compte, le changement de mot
       de passe et l'edition de Dossier. Faire du nouveau style le DEFAUT
       changeait l'apparence de ces trois ecrans sans que personne ne l'ait
       demande — releve par MASTER a la revue de T1871.
       Le defaut reste donc l'habillage d'origine, a l'octet pres, et seul le
       formulaire de profil demande l'autre. Meme motif que
       `longPressSelection` en T1870 : une surface demande, les autres ne
       changent pas. */
    'premium' => false,
])

@php
    /* Un champ en faute le DIT, sans qu'on ait a le repeter : le nom suffit
       a savoir s'il porte une erreur. Inutile de poser `aria-invalid` a la
       main sur chacun des vingt champs du profil — vingt occasions d'en
       oublier un. `aria-describedby` vise l'`id` que `x-input-error` pose a
       partir du meme nom.
       Un champ sans `name`, ou sans erreur, ne recoit aucun de ces
       attributs : le rendu est alors identique a ce qu'il etait. */
    $_nom = $attributes->get('name');
    $_enFaute = $_nom && $errors->has($_nom);

    /* TASK-1871 — `data-server-invalid` : LE SERVEUR A DIT NON.
       =========================================================
       Marqueur indispensable, et la raison en est un vrai defaut releve par
       MASTER : `checkValidity()` ne connait que les contraintes HTML. Un
       slug deja pris par quelqu'un d'autre lui parait parfaitement valide —
       seul le serveur sait qu'il ne l'est pas.
       Sans ce marqueur, la validation au fil de la saisie effacait l'erreur
       SERVEUR des que la forme du champ redevenait correcte : le message
       disparaissait alors que le probleme demeurait, et plus rien ne
       prevenait avant un nouvel envoi. */
    $_classesOrigine = 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm';

    /* LE RAIL D'ETAT, et le fond qui se detache de son conteneur.
       -----------------------------------------------------------
       Le formulaire du profil fait plus de six cents lignes et tous ses
       champs se ressemblent : il manquait un endroit FIXE ou lire l'etat du
       champ qu'on remplit. Une bordure epaisse a gauche, eteinte au repos,
       allumee au focus, rouge en faute. Le reflexe aurait ete un contour
       rouge integral : il crie, il double le message deja ecrit dessous, et
       il devient une tache sur fond sombre. Ici la POSITION porte
       l'information autant que la teinte, ce qui sert aussi qui distingue
       mal les couleurs.

       Le fond est `--bp-surface`, PAS `--bp-panel` : ce dernier peint deja
       le bloc qui contient le formulaire, et en sombre les deux valaient
       `#050F2C` — le champ disparaissait dans son propre conteneur.

       La bordure est MESUREE : `--bp-border` plafonne a 1.48-1.71 contre ce
       fond selon le theme, quand WCAG 1.4.11 demande 3:1 pour la limite d'un
       controle. Melange calcule sur les cinq themes, clair et sombre :
       35% -> 2.23 · 45% -> 2.83 · 55% -> 3.55. Retenu 55%.

       Et AUCUN modificateur d'opacite sur une variable CSS : `/25` rend une
       couleur INVALIDE, donc pas d'anneau du tout. Piege paye en T1475,
       remesure en T1771. */
    $_classesPremium = 'w-full rounded-lg border border-[color:color-mix(in_srgb,var(--bp-muted)_55%,var(--bp-surface))] bg-[color:var(--bp-surface)] px-3 py-2 text-[color:var(--bp-text)] shadow-sm transition-colors duration-150 placeholder:text-[color:var(--bp-muted)] focus:outline-none focus:border-[color:var(--bp-primary)] focus:ring-2 focus:ring-[color:var(--bp-primary)] '
        .($_enFaute
            ? 'border-s-[3px] border-s-red-500 focus:border-s-red-500'
            : 'border-s-[3px] border-s-transparent focus:border-s-[color:var(--bp-primary)]');
@endphp

<input @disabled($disabled)
    @if($_enFaute) aria-invalid="true" aria-describedby="{{ $_nom }}-error" data-server-invalid @endif
    {{ $attributes->merge(['class' => $premium ? $_classesPremium : $_classesOrigine]) }}>
