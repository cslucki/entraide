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
{{-- `role="alert"` le fait annoncer des l'arrivee sur la page, et `tabindex`
     le rend focusable pour qu'on puisse y revenir.

     Il n'emporte PLUS le focus automatiquement. Demande de Cyril, 20/11 :
     « quand on s'est trompé ou on a omis une information sur un champ, on
     soit directement redirigé vers lui et qu'on voie le message d'erreur ».
     Amener l'oeil sur un resume en haut, puis le laisser chercher le champ,
     c'etait resoudre la moitie du probleme. Le focus va donc au CHAMP (voir
     le script en pied de ce fichier), et ce bloc reste la pour la vue
     d'ensemble et pour les lecteurs d'ecran. --}}
<div role="alert"
     tabindex="-1"
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

{{-- TASK-1871 — aller DIRECTEMENT au premier champ en faute.

     Le marqueur est celui que `x-text-input` pose deja tout seul :
     `aria-invalid="true"`. On ne re-derive donc aucune condition ici, et il
     n'y a aucune liste a tenir a jour — le champ se declare lui-meme.

     `scrollIntoView` avant `focus` : le focus seul saute parfois a ras du
     bord de l'ecran, et le libelle du champ se retrouve hors vue. Centrer
     d'abord, donner le focus ensuite.

     `preventScroll` evite que le focus refasse son propre saut par-dessus
     celui qu'on vient de regler.

     Sans JavaScript, rien de tout cela ne manque vraiment : la synthese
     ci-dessus est en tete de page, et ses liens menent aux memes champs. --}}
<script>
    (function () {
        var premier = document.querySelector('[aria-invalid="true"]');
        if (! premier) { return; }

        premier.scrollIntoView({ block: 'center', behavior: 'smooth' });
        premier.focus({ preventScroll: true });
    })();
</script>
@endif
