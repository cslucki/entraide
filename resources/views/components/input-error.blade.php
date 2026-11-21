@props([
    'messages',
    // TASK-1871 — le nom du champ decrit. Quand il est donne, ce bloc porte
    // l'`id` que `x-text-input` vise par `aria-describedby`.
    //
    // C'est le SEUL ajout de cette TASK a ce composant, et il est invisible :
    // ce fichier sert dix-huit ecrans, et l'habillage y reste celui d'avant.
    // Une premiere version y ajoutait un pictogramme ; retiree a la revue
    // MASTER, qui demande d'isoler l'evolution visuelle au seul formulaire
    // de profil.
    'field' => null,
])

@if ($messages)
    <ul @if($field) id="{{ $field }}-error" @endif {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@elseif ($field)
    {{-- TASK-1872 correctif 03 — L'EMPLACEMENT DU MESSAGE EST CHOISI ICI,
         jamais devine par le script.
         ====================================================================
         Recette de Cyril, 21/11, capture a l'appui : l'alerte de « Adresse de
         votre profil » s'affichait A DROITE du champ, en colonne etroite.

         La cause n'etait pas le style mais la POSITION. Faute de bloc
         existant, `x-form-live-validation` creait le sien et l'inserait
         `afterend` du champ — or ce champ-la vit dans un conteneur
         `flex items-center`. Le message devenait donc un FRERE du champ dans
         une rangee horizontale, et la rangee l'a mis a cote.

         Le composant emet desormais l'emplacement, vide et masque, a l'endroit
         que la vue a choisi : sous le champ, hors du `flex`. Le script n'a plus
         qu'a le remplir. Serveur et navigateur ecrivent au MEME endroit, ce qui
         rend les deux origines indiscernables — c'etait deja l'intention de
         T1871, il lui manquait l'ancre.

         Emis seulement quand `field` est donne, c'est-a-dire sur le seul
         formulaire de profil : les dix-sept autres ecrans servis par ce
         composant ne rendent pas une balise de plus. --}}
    <ul id="{{ $field }}-error" hidden {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}></ul>
@endif
