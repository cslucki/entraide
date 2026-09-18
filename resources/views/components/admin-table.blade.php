{{--
    TASK-1503 — l'enveloppe des tableaux d'administration d'Organisation.

    Elle ne rend RIEN d'elle-même : le tableau reste celui de la page, ligne
    par ligne, cellule par cellule. Elle pose la classe qui, sous `md`, replie
    chaque ligne en carte (`resources/css/app.css`, bloc `.bp-admin-table`).

    Contrat pour la page :
    - `<td data-label="{{ __('…') }}">` sur chaque cellule, MÊME clé que le <th> ;
    - `<td data-title>` sur la cellule qui nomme la ligne (pleine largeur) ;
    - `<td data-actions>` sur la cellule des actions (pied de carte, cibles 40 px) ;
    - `<td data-empty colspan="…">` sur l'état vide.

    Pourquoi pas un composant qui reconstruit les lignes : il aurait fallu
    décrire deux fois chaque ligne (tableau, carte) sur treize pages, et les
    deux auraient divergé au premier changement. Ici la page ne change que par
    des attributs, et le test TASK1503 mesure qu'aucune cellule ne les oublie.
--}}
<div {{ $attributes->merge(['class' => 'bp-admin-table']) }} data-admin-table>
    {{ $slot }}
</div>
