{{--
    Les invitations par courriel envoyees pour cette Boucle.

    Seulement celles-la : rejoindre depuis l'Organization est immediat, il n'y a
    rien a accepter et donc rien a suivre.
--}}
@if($emailInvitations->isEmpty())
    <p class="text-xs leading-5 text-[var(--bp-muted)]">{{ __('loops.members_invitations_empty') }}</p>
@else
    <x-loops.invitation-list :invitations="$emailInvitations" />
@endif
