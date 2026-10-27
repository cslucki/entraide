{{-- Owner names for a Loop, without ever implying one of them is primary.

     One owner shows a name; two show both; beyond that the first plus a count,
     because a catalogue card has no room for five names and none of them
     outranks the others.

     Expects `owners` to be eager-loaded — this renders inside lists.

     ## TASK-1707 M3 — les noms menent au profil

     Cyril l'a demande a deux endroits : la fiche d'une Boucle (« Animee par
     Ofsh Cent ») et la carte de la liste des Boucles. Ce sont les deux SEULS
     appels de ce composant : la correction y vit donc une fois.

     Trois choses que ce fichier decide, et qu'il faut lire :

     1. **L'URL est bornee a l'Organization de l'URL courante.** `profile.show`
        est une route FRONT, et la route courte resout l'Organization PAR
        DEFAUT : depuis `/org/{slug}/...` un lien non borne fait basculer de
        tenant. C'est exactement la famille de defauts que TASK-1697 a ferme
        sur neuf liens.

     2. **Un compte desactive n'est PAS lie.** Son nom est deja remplace par
        « Utilisateur desactive » : pointer vers son profil promettrait une
        page qui ne dit rien de lui.

     3. **Au-dela de deux proprietaires, le resume reste SANS lien.** Lier le
        seul nom cite parmi plusieurs egaux reintroduirait la primaute que ce
        composant refuse depuis sa premiere ligne. --}}
@props(['owners', 'fallback' => null])

@php
    $orgParam = request()->route('organization');

    $profil = function ($user) use ($orgParam): ?string {
        if (! $user || $user->isDeactivated()) {
            return null;
        }

        return $orgParam && Route::has('organization.profile.show')
            ? route('organization.profile.show', ['organization' => $orgParam, 'user' => $user->profileRouteKey()])
            : route('profile.show', $user->profileRouteKey());
    };

    $proprietaires = $owners
        ->map(fn ($m) => $m->user)
        ->filter()
        ->map(fn ($u) => ['nom' => $u->publicDisplayName(), 'href' => $profil($u)])
        ->filter(fn ($o) => filled($o['nom']))
        ->values();
@endphp

@if($proprietaires->isEmpty())
    {{ $fallback ?? '—' }}
@elseif($proprietaires->count() <= 2)
    @foreach($proprietaires as $proprietaire)
        @if(! $loop->first), @endif
        @if($proprietaire['href'])
            <a href="{{ $proprietaire['href'] }}" data-loop-owner-profile class="underline decoration-dotted underline-offset-2 hover:decoration-solid">{{ $proprietaire['nom'] }}</a>
        @else
            {{ $proprietaire['nom'] }}
        @endif
    @endforeach
@else
    {{ __('loops.governance_and_others', ['name' => $proprietaires->first()['nom'], 'count' => $proprietaires->count() - 1]) }}
@endif
