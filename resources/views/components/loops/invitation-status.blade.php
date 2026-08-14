{{--
    Single status pill for a Loop invitation, shared by every screen.

    An invitation still flagged `pending` in database but past its window reads
    as expired here: the row is only flipped when it is next touched, and the UI
    must not claim it is still live in the meantime.
--}}
@props(['invitation'])

@php
    $state = match (true) {
        $invitation->isRevoked() => 'revoked',
        $invitation->isAccepted() => 'accepted',
        $invitation->isExpired() => 'expired',
        default => 'pending',
    };

    $styles = [
        'pending' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
        'accepted' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
        'expired' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        'revoked' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium '.$styles[$state]]) }}>
    {{ __('loops.invitations_status_'.$state) }}
</span>
