{{-- TASK-1795 correctif 2 — repli Blade de `private_message_received`.

     Dernier cran de `SystemEmailTemplateResolver` : il ne sert que si AUCUNE
     ligne `system_email_templates` ne repond, gabarit desactive compris. Il dit
     donc la meme chose que le gabarit FR, sans jamais parler d'« echange » :
     une discussion privee n'en est pas un.

     L'URL arrive de la notification — jamais `route('messages.show')` ici, qui
     rendrait la surface courte et sa 404. --}}
@component('mail::message')
# Message privé

Bonjour {{ $user->fullName }},

Vous avez reçu un message privé de **{{ $message->sender?->fullName ?? 'Système' }}**@if($organizationName !== '') sur {{ $organizationName }}@endif.

@component('mail::panel')
{{ Str::limit(strip_tags($message->body ?? ''), 300) }}
@endcomponent

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Lire le message et répondre
@endcomponent

_Vous recevez cet email car ce message vous est adressé._
@endcomponent
