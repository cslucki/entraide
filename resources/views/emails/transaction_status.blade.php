{{--
    TASK-1760 correctif 2 — le REPLI de l'email de statut d'echange.

    ## Ce qu'il disait

    « L'acheteur a declare la prestation terminee. En attente de votre
    confirmation. » — deux mots bannis par le critere n°1 de la TASK A, dans un
    email que les membres recoivent. Et le gabarit ENTIER etait en francais code
    en dur : pas un seul `__()`. Un membre anglophone recevait du francais.

    ## Pourquoi c'etait LATENT et pas livre

    Le gabarit administrable `transaction_status_changed` existe en base dans les
    deux locales, actif, et il est propre. C'est lui qui part. Ce fichier ne sert
    que si quelqu'un desactive le gabarit — mais un repli qui ne sait parler
    qu'une langue n'est pas un repli.

    ## La phrase vient de `CarteEntraide::phrase()`

    Et non de nouveaux libelles : c'est la phrase CANONIQUE, orientee role,
    deja traduite et deja testee (T1755). `$user` est le destinataire, donc
    l'aidant lit « Barnabe dit avoir recu l'aide, confirmez… » et l'aide lit
    « Vous avez dit avoir recu l'aide, Ottoline doit confirmer » — ce que
    l'ancienne phrase neutre ne pouvait pas faire.

    L'email dit donc exactement ce que la carte dit dans l'application.

    ## La langue

    `__()` suit `app()->getLocale()`, **exactement comme le gabarit en base** :
    `SystemEmailTemplateResolver` fait `$locale ??= app()->getLocale()`. Les deux
    chemins se comportent donc pareil. Que la langue du DESTINATAIRE ne soit pas
    honoree est un sujet transverse a tous les emails (`User` n'implemente pas
    `HasLocalePreference`) — signale, hors de ce correctif.
--}}
@component('mail::message')
# {{ __('messages.email_exchange.title') }}

{{ __('messages.email_exchange.greeting', ['prenom' => $user->first_name ?: $user->fullName]) }}

{{ __('messages.email_exchange.intro', ['statut' => $transaction->status_label]) }}

@php
    $service = $transaction->service;
    $titre = $service
        ? $service->title
        : ($transaction->serviceRequest?->title ?? __('messages.email_exchange.untitled'));
    $phrase = \App\Support\Entraide\CarteEntraide::phrase($transaction, $user);
@endphp

**{{ __('messages.email_exchange.label_exchange') }} :** {{ $titre }}
**{{ __('messages.email_exchange.label_time') }} :** {{ \App\Support\Entraide\TempsPartage::libelle($transaction->points_agreed ?? $transaction->points_proposed) }}

@component('mail::button', ['url' => route('messages.show', $transaction), 'color' => 'primary'])
{{ __('messages.email_exchange.cta') }}
@endcomponent

@if($phrase)
{{ $phrase }}
@endif
@endcomponent
