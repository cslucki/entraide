# LT-001 — Audit cible

## 1. Verdict

**GO AVEC RESERVE**

LT-001 peut rester une Low Task si le perimetre est strict : ajouter une action admin qui declenche le broker Laravel existant pour envoyer un lien de reset a un Member, sans generer de mot de passe, sans exposer le token, sans refonte auth.

Reserve principale : `/admin/email-templates` existe, mais n'est pas actuellement branche au systeme reel d'envoi des emails applicatifs ni au reset password. Il ne faut pas presenter ca comme deja utilisable pour le reset.

## 2. Resume du systeme `/admin/email-templates` existant

Routes presentes dans `routes/web.php` sous middleware `auth` + `admin` :

- `admin.email-templates`
- create/store/show/edit/update/destroy
- `admin.email-templates.preview`

Controleur : `app/Http/Controllers/Admin/AdminEmailTemplatesController.php`

Modele : `app/Models/EmailTemplate.php`

Tables :

- `email_templates` : `slug`, `name`, `subject`, `content_html`, `variables`
- `email_logs` : `template_id`, `user_id`, `to_email`, `subject`, `status`, `error_message`, `data`

Seeder actuel : `database/seeders/EmailTemplateSeeder.php`

Templates seedes :

- `welcome`
- `transaction-status`

Constat : le CRUD template est reel, mais il n'existe pas de service de rendu, pas de substitution centralisee, pas de lien entre `EmailTemplate` et les notifications applicatives actuelles.

## 3. Reset password Laravel actuel : etat reel

Le reset password est le flux Laravel standard.

Routes globales chargees via `routes/auth.php` :

- `GET forgot-password`
- `POST forgot-password`
- `GET reset-password/{token}`
- `POST reset-password`

Routes equivalentes sous prefixe Organization legacy `/{community}` existent aussi dans `routes/web.php`, nommees `community.password.*`.

Controleurs :

- `PasswordResetLinkController` utilise `Password::sendResetLink($request->only('email'))`
- `NewPasswordController` utilise `Password::reset(...)`

Config :

- broker `users`
- table `password_reset_tokens`
- expiration 60 min
- throttle 60 sec

Le modele `User` n'override pas `sendPasswordResetNotification`, donc Laravel envoie la notification de reset par defaut.

Note : il existe deja un lien public "Mot de passe oublie ?" dans `resources/views/auth/login.blade.php`. LT-001 ne doit pas en ajouter un autre.

## 4. Point d'integration recommande

Minimum viable propre :

- ajouter une route admin dediee du type `POST /admin/users/{user}/send-password-reset`
- placer l'action pres des actions admin users existantes dans `AdminController`
- appeler `Password::broker()->sendResetLink(['email' => $user->email])`
- afficher un succes generique, sans token
- ajouter le bouton dans la ligne Member admin ou, mieux, dans l'ecran edit Member

Pour s'appuyer sur `/admin/email-templates`, ne pas creer de feature transverse. Deux options :

- Option LT stricte : utiliser le reset Laravel par defaut, ne pas toucher aux email templates. C'est le plus petit scope.
- Option LT avec template minimal : ajouter seulement un template `password-reset` au seeder et une notification reset dediee qui lit ce template. Ca demande quand meme de brancher `User::sendPasswordResetNotification`, donc c'est plus risque que l'option stricte.

## 5. Fichiers probablement concernes

Si LT stricte :

- `routes/web.php`
- `app/Http/Controllers/Admin/AdminController.php`
- `resources/views/admin/users.blade.php` ou `resources/views/admin/users/edit.blade.php`
- tests admin users

Si integration template minimale :

- `database/seeders/EmailTemplateSeeder.php`
- `app/Models/User.php`
- nouvelle notification dediee, probablement `app/Notifications/ResetPasswordNotification.php`
- tests email/template/reset

## 6. Risques securite

- Scope Organization : `/admin/users` est actuellement global pour `is_admin`. Si "Organization Admin" signifie admin limite a son Organization, il faut imperativement verifier que le Member cible appartient a l'Organization administree.
- Ne jamais generer ni afficher le token cote admin.
- Ne jamais logger le token dans `email_logs.data`.
- Respecter le throttle du broker Laravel.
- Ne pas permettre a un admin Organization d'envoyer un reset a un Member hors perimetre.
- Ne pas remplacer l'ancien flux par une implementation manuelle de token.
- L'action actuelle `changePassword` permet de definir un mot de passe en clair cote formulaire admin ; LT-001 devrait plutot introduire l'envoi de lien et eviter d'etendre ce pattern.

## 7. Tests minimaux

- admin autorise peut declencher l'envoi du reset link pour un Member.
- non-admin recoit 403.
- le token est cree en base via `password_reset_tokens`.
- une notification reset est envoyee, sans exposer le token dans la reponse.
- si Organization Admin est inclus dans le scope : admin Organization ne peut pas agir sur un Member d'une autre Organization.
- validation d'un Member banni ou sans email selon regle produit a preciser.
- si template DB utilise : test du template `password-reset` et fallback si template absent.

## 8. Proposition de perimetre final LT-001

Perimetre recommande Low Task :

- Ajouter une action "Envoyer un lien de reinitialisation" dans l'admin Member existant.
- Utiliser le broker Laravel existant.
- Ne pas modifier le flux public login/reset.
- Ne pas generer de mot de passe.
- Ne pas exposer de token.
- Restreindre l'action aux admins autorises.
- Ajouter les tests feature minimaux.

Decision sur `/admin/email-templates` : ne pas le rendre obligatoire pour LT-001. Il n'est pas pret a porter le reset password sans petit branchement supplementaire.

## 9. Hors scope

- Refonte auth.
- Nouveau systeme transverse de templates email.
- Ajout d'un lien public "mot de passe oublie".
- Generation de mot de passe temporaire.
- Refactor complet Admin Organization / Community Admin.
- Migration terminologique large Community vers Organization.
- Refonte des notifications existantes `welcome`, `transaction-status`, `new_message`.
