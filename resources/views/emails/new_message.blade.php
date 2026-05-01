@component('mail::message')
# Nouveau message reçu

Bonjour {{ $user->name }},

**{{ $message->sender?->name ?? 'Système' }}** vous a envoyé un message :

@component('mail::panel')
{{ Str::limit($message->body, 300) }}
@endcomponent

@component('mail::button', ['url' => route('messages.show', $transaction), 'color' => 'primary'])
Répondre
@endcomponent

_Vous recevez cet email car vous êtes partie à cet échange._
@endcomponent
