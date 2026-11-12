{{-- TASK-1795 correctif 1 — l'URL arrive de la notification, elle n'est PLUS
     fabriquee ici. `route('messages.show', $transaction)` rendait la surface
     courte, ou l'Organization par defaut est liee : une conversation d'une
     autre Organization y repond 404. Le repli Blade reintroduisait donc le
     defaut meme une fois le chemin gabarit corrige. --}}
@component('mail::message')
# Nouveau message reçu

Bonjour {{ $user->fullName }},

**{{ $message->sender?->fullName ?? 'Système' }}** vous a envoyé un message :

@component('mail::panel')
{{ Str::limit($message->body, 300) }}
@endcomponent

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Répondre
@endcomponent

_Vous recevez cet email car vous êtes partie à cet échange._
@endcomponent
