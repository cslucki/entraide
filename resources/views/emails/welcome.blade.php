{{--
    TASK-1694 bug G — ce repli nommait la MARQUE, pas l'Organization.

    Il disait « Bienvenue sur Entraide » a quelqu'un qui venait de rejoindre
    « Organismes de formation solidaires et humanistes ». Le bug E avait corrige
    la LIGNE EN BASE ; ce fichier-ci est l'autre moitie du meme defaut, et c'est
    LUI qui partait reellement — la resolution ne trouvait aucun gabarit pour
    cette Organization.

    `$organisation` est passe par la notification. Repli sur le nom de
    l'application si la personne n'a pas d'Organization : un email sans nom du
    tout serait pire qu'un nom approximatif.
--}}
@php
    $_organisation = ($user instanceof \App\Models\User ? $user->organization?->name : null) ?: config('app.name');
@endphp
@component('mail::message')
# Bienvenue sur {{ $_organisation }}, {{ $user->fullName }} !

Votre compte a bien été créé. Vous avez reçu **100 points de bienvenue** pour démarrer vos premiers échanges.

@component('mail::button', ['url' => route('explorer'), 'color' => 'primary'])
Découvrir les services
@endcomponent

**Que faire maintenant ?**

- Publiez votre premier service pour gagner des points
- Parcourez les offres disponibles dans l'explorateur
- Complétez votre profil (bio, localisation, avatar)

À très bientôt sur {{ $_organisation }} !
@endcomponent
