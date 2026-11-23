{{--
    TASK-1885 C3 (demande Cyril, 22/11) — un nom (ou un avatar) qui mene au
    profil public du membre, la ou l'on n'avait qu'un texte.

    Le lien n'existe que s'il mene quelque part : membre connecte, Organization
    courante connue, auteur affichable DANS cette Organization (ni desactive, ni
    membre d'un autre tenant — `profile.show` est derriere `organization.member`).
    Sinon, le contenu est rendu tel quel, sans lien : jamais d'adresse morte.

    L'adresse vient de `FeedEventRenderer::lienProfil()`, deja utilise par les
    avatars des commentaires du Flux : une seule regle de construction.

    Usage : <x-user-profile-link :user="$auteur" class="...">Nom</x-user-profile-link>
--}}
@props(['user' => null])
@php
    $_organisation = currentOrganization();
    $_lienProfil = (auth()->check() && $user !== null && $_organisation !== null && $user->isDisplayableIn($_organisation))
        ? \App\Support\Feed\FeedEventRenderer::lienProfil($user, $_organisation->slug)
        : null;
@endphp
@if($_lienProfil)
<a href="{{ $_lienProfil }}" data-user-profile-link="{{ $user->id }}" {{ $attributes->merge(['class' => 'hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-sm']) }}>{{ $slot }}</a>
@else
<span {{ $attributes }}>{{ $slot }}</span>
@endif
