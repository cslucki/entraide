@component('mail::message')
# Bienvenue sur Entraide, {{ $user->name }} !

Votre compte a bien été créé. Vous avez reçu **100 points de bienvenue** pour démarrer vos premiers échanges.

@component('mail::button', ['url' => route('explorer'), 'color' => 'primary'])
Découvrir les services
@endcomponent

**Que faire maintenant ?**

- Publiez votre premier service pour gagner des points
- Parcourez les offres disponibles dans l'explorateur
- Complétez votre profil (bio, localisation, avatar)

À très bientôt sur Entraide !
@endcomponent
