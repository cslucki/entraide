{{-- TASK-1795 correctif 7 — « Se connecter sous », depuis l'ecran des messages.

     Cyril : « on va ajouter sur les utilisateurs un lien. Ce lien permettra de
     "Se connecter sous", comme on peut le faire depuis /admin/users. Et donc
     sur les "Messages privés", ce lien figurera sur Expéditeur et
     Destinataire. »

     ## Un geste offert doit etre honore

     `AdminController::loginAsUser()` refuse TROIS cas : un compte banni, un
     autre administrateur, et — implicitement — soi-meme, qui n'aurait aucun
     sens. L'ecran `/admin/users` ne verifie que `banned_at` : il propose donc
     le bouton sur un administrateur, qui le refuse ensuite par un message
     d'erreur. On ne reproduit pas ce decalage ici : les trois gardes sont
     posees, et le lien n'apparait que la ou il aboutira. C'est la regle du
     bug K de TASK-1693, deja tenue par la date de cette meme table.

     Le refus n'est pas silencieux pour autant : quand le lien n'est pas
     offert, le `title` dit pourquoi.

     ## Pourquoi un POST, et pourquoi un formulaire par personne

     La route est `POST admin/users/{user}/login-as` : changer d'identite est
     une ECRITURE de session, pas une navigation. Un `<a href>` serait
     rejouable par un prefetch du navigateur.

     Le formulaire `bulk-messages` de cette page se FERME avant le tableau (les
     cases a cocher s'y rattachent par `form="bulk-messages"`) : ces
     formulaires-ci ne sont donc imbriques dans aucun autre.

     @param \App\Models\User|null $utilisateur
--}}
@php
    $peutSeConnecterSous = $utilisateur
        && $utilisateur->id !== auth()->id()
        && ! $utilisateur->banned_at
        && ! $utilisateur->is_admin;

    $raisonDuRefus = match (true) {
        ! $utilisateur => null,
        $utilisateur->id === auth()->id() => __('admin.login_as_self'),
        (bool) $utilisateur->banned_at => __('admin.login_as_banned'),
        (bool) $utilisateur->is_admin => __('admin.login_as_admin'),
        default => null,
    };
@endphp

@if($peutSeConnecterSous)
    <form method="POST" action="{{ route('admin.users.login-as', $utilisateur) }}" class="mt-0.5">
        @csrf
        <button class="text-[11px] font-medium text-amber-600 hover:underline whitespace-nowrap"
                data-login-as="{{ $utilisateur->id }}">
            {{ __('admin.login_as_action') }}
        </button>
    </form>
@elseif($raisonDuRefus)
    <span class="mt-0.5 block text-[11px] text-gray-400"
          data-login-as-unavailable="{{ $utilisateur->id }}"
          title="{{ $raisonDuRefus }}">{{ $raisonDuRefus }}</span>
@endif
