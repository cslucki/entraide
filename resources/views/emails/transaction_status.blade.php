@component('mail::message')
# Mise à jour de votre échange

Bonjour {{ $user->name }},

Le statut de votre échange a changé : **{{ $transaction->status_label }}**.

@php
    $service = $transaction->service;
    $title = $service ? $service->title : ($transaction->serviceRequest?->title ?? 'Échange');
@endphp

**Échange :** {{ $title }}
**Points :** {{ $transaction->points_agreed ?? $transaction->points_proposed }} pts

@component('mail::button', ['url' => route('messages.show', $transaction), 'color' => 'primary'])
Voir la conversation
@endcomponent

@switch($transaction->status)
@case('accepted')
La proposition a été acceptée. L'échange est maintenant en cours.
@break
@case('refused')
La proposition a été refusée.
@break
@case('buyer_done')
L'acheteur a déclaré la prestation terminée. En attente de votre confirmation.
@break
@case('completed')
L'échange est complété et les points ont été transférés. Merci !
@break
@case('cancelled')
L'échange a été annulé.
@break
@endswitch
@endcomponent
