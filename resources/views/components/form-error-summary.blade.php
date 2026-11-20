@props([
    // Le titre du bloc. Jamais de phrase en dur ici : l'appelant passe une
    // clef traduite, ce composant ne connait aucun formulaire en particulier.
    'title' => null,
    // TASK-1871 — les SEULS champs vers lesquels un lien est permis.
    //
    // `clef de validation => id de l'element`. Une clef absente de cette liste
    // rend son message en TEXTE, sans lien. C'est volontaire et c'est une
    // exigence : un lien vers une ancre inexistante ne mene nulle part, et il
    // trahit d'autant plus la promesse qu'il a l'air cliquable.
    //
    // Deux familles restent donc sans lien dans le profil : `bio`, rendu par
    // un editeur qui ne pose pas d'`id`, et `profile_links.*`, dont les clefs
    // portent un indice de ligne qui n'existe pas dans le DOM.
    'anchors' => [],
])

@php
    /* TASK-1871 — un message, une ligne.
       `$errors->all()` rend un message par regle echouee : le meme texte peut
       donc revenir deux fois (deux liens mal formes, par exemple). MASTER
       demande explicitement de ne pas le repeter. On deduplique sur le
       message LUI-MEME, pas sur la clef, parce que c'est le texte que la
       personne lit. */
    $_lignes = [];
    $_vus = [];

    foreach ($errors->keys() as $_clef) {
        foreach ($errors->get($_clef) as $_message) {
            if (in_array($_message, $_vus, true)) {
                continue;
            }
            $_vus[] = $_message;
            $_lignes[] = ['message' => $_message, 'ancre' => $anchors[$_clef] ?? null];
        }
    }
@endphp

@if($_lignes !== [])
{{-- `role="alert"` le fait annoncer des l'arrivee sur la page ; `tabindex`
     le rend focusable, et `autofocus` l'amene sous les yeux SANS une ligne de
     JavaScript — le navigateur fait lui-meme defiler jusqu'a l'element
     autofocus. C'est ce qui remplace le defilement manuel que Cyril
     decrivait.

     Un `autofocus` existe deja plus bas dans le formulaire du profil, sur le
     champ « nom ». Le premier dans l'ordre du document l'emporte : ce bloc
     quand il existe, le champ quand tout va bien. --}}
<div role="alert"
     tabindex="-1"
     autofocus
     data-form-error-summary
     {{ $attributes->merge(['class' => 'rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-700/60 dark:bg-red-900/25 dark:text-red-200']) }}>
    <p class="font-semibold">{{ $title }}</p>
    <ul class="mt-2 list-disc space-y-1 ps-5">
        @foreach($_lignes as $_ligne)
            <li>
                @if($_ligne['ancre'])
                    {{-- Un lien d'ancrage : il fonctionne sans JavaScript, et
                         il DEPLACE le focus sur le champ vise, puisque celui-ci
                         est un controle focusable. --}}
                    <a href="#{{ $_ligne['ancre'] }}" class="underline underline-offset-2 hover:no-underline">{{ $_ligne['message'] }}</a>
                @else
                    {{ $_ligne['message'] }}
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endif
