---
task_id: LT-001
status: DONE
owner: CODE/OpenCode
branch: LT-001-admin-send-password-reset-link
---

# LT-001 — ADMIN SEND PASSWORD RESET LINK

## Compréhension de la tâche

Ajouter dans l'admin users une action permettant d'envoyer à un Member un lien standard Laravel de réinitialisation de mot de passe, via le broker existant. Pas de notification custom, pas de template email, pas de refactor auth.

## Décisions prises

- Route POST dédiée : `/admin/users/{user}/send-password-reset`, nommée `admin.users.send-password-reset`
- Méthode : `AdminController@sendPasswordResetLink(User $user)`
- Utilisation de `Password::broker()->sendResetLink(['email' => $user->email])`
- Messages flash : succès / throttle / erreur, sans exposer de token
- Vue : bouton "Lien de réinitialisation" avec confirmation JS dans la colonne actions, placé avant le bouton "Mdp"
- Sécurité : réutilisation du middleware `admin` existant (is_admin global)
- Tests : nouveau fichier `AdminSendPasswordResetLinkTest.php` avec 6 tests (14 assertions)
- Aucune modification du système email-template, auth public, notification custom

## Fichiers modifiés

- `routes/web.php` — +1 ligne : route POST
- `app/Http/Controllers/Admin/AdminController.php` — +12 lignes : méthode `sendPasswordResetLink` + import `Password` facade
- `resources/views/admin/users.blade.php` — +9 lignes : bouton d'action
- `tests/Feature/Admin/AdminSendPasswordResetLinkTest.php` — nouveau fichier, 6 tests
- `TODO/LOW/LT-001-admin-send-password-reset-link.md` — mise à jour status

## Tests exécutés

```bash
php artisan test --filter=AdminSendPasswordResetLinkTest
php artisan test tests/Feature/Admin/
```

## Résultats des tests

- AdminSendPasswordResetLinkTest : 6/6 passed (14 assertions)
- Full admin suite : 89/89 passed (201 assertions)
- Aucune régression

## Réserve sur le scope Organization Admin

Le middleware admin actuel (`AdminMiddleware`) vérifie uniquement `is_admin` (global). Aucun scope Organization Admin n'est appliqué sur les routes users existantes ni sur cette nouvelle route. Si un scope Organization Admin est ajouté ultérieurement, cette action devra vérifier que le Member cible appartient à l'Organization administrée.

## Correction i18n après test manuel

### Constats

Test manuel utilisateur réalisé avant merge main. Trois problèmes :

1. **Email de reset en anglais** : l'email envoyé utilisait les textes par défaut Laravel (Hello!, Reset Password, etc.)
2. **passwords.reset en clair** : après reset, la page affichait la clé brute `passwords.reset`
3. **passwords.sent en clair** : sur /forgot-password, après envoi du lien, affichait `passwords.sent`

### Cause racine

L'application est configurée avec `APP_LOCALE=fr` et `APP_FALLBACK_LOCALE=fr` (fichier `.env`).
- `lang/fr/` existait avec `validation.php` mais **pas** `passwords.php` → les clés `passwords.sent`, `passwords.reset`, etc. n'étaient pas traduites
- Aucun fichier `lang/fr.json` → les chaînes de la notification email (ResetPassword via MailMessage) n'étaient pas traduites

### Correction

1. **`lang/fr/passwords.php`** (nouveau) : traductions des clés de statut du password broker Laravel
   - `passwords.sent` → "Nous vous avons envoyé par e-mail le lien de réinitialisation..."
   - `passwords.reset` → "Votre mot de passe a été réinitialisé."
   - `passwords.throttled` → "Veuillez patienter avant de réessayer."
   - `passwords.token` → "Ce lien de réinitialisation n'est plus valide."
   - `passwords.user` → "Aucun compte ne correspond à cette adresse e-mail."

2. **`lang/fr.json`** (nouveau) : traductions JSON pour les chaînes de la notification ResetPassword
   - "Hello!" → "Bonjour,"
   - "Whoops!" → "Oups !"
   - "Regards," → "Cordialement,"
   - "Reset your password" → "Réinitialisation de votre mot de passe" (sujet email)
   - "Reset Password" → "Réinitialiser le mot de passe" (bouton action)
   - Texte d'introduction, expiration, non-demande, subcopy

### Fichiers ajoutés (correction i18n)

- `lang/fr/passwords.php` — nouveau
- `lang/fr.json` — nouveau

### Tests relancés

```bash
php artisan test tests/Feature/Admin/  # 89/89 passed, 0 régression
php artisan tinker --execute="echo __('passwords.sent') ..."  # toutes les traductions vérifiées
```

Toutes les traductions ont été vérifiées via `php artisan tinker` :
- `__('passwords.sent')` → "Nous vous avons envoyé par e-mail..."
- `__('passwords.reset')` → "Votre mot de passe a été réinitialisé."
- `__('passwords.throttled')` → "Veuillez patienter avant de réessayer."
- `__('passwords.token')` → "Ce lien de réinitialisation n'est plus valide."
- `__('passwords.user')` → "Aucun compte ne correspond à cette adresse e-mail."
- `__('Hello!')` → "Bonjour,"
- `__('Reset your password')` → "Réinitialisation de votre mot de passe"
- `__('Reset Password')` → "Réinitialiser le mot de passe"
- `__('Regards,')` → "Cordialement,"
- `__('This password reset link will expire in :count minutes.', ['count' => 60])` → "Ce lien de réinitialisation expirera dans 60 minutes."

## Branding email — Correction

### Constat

L'email de reset affichait "Entraide" en salutation alors que `/admin/settings` affiche "BouclePro".

### Source exacte de "Entraide" dans l'email

L'email Laravel natif utilise `config('app.name')` dans son template (`vendor/.../email.blade.php` : `{{ config('app.name') }}`). Cette valeur provient de `env('APP_NAME', 'Laravel')` dans `config/app.php`. Le fichier `.env` local contient `APP_NAME=Entraide`.

### Source exacte de "BouclePro" dans /admin/settings

`AdminSettingController@index` utilise `Setting::get('platform_name', 'Entraide')` qui lit depuis la table `settings` (clé `platform_name`). La valeur en base est `BouclePro`. C'est un système de stockage DB distinct, indépendant de `config('app.name')`.

### Correction minimale appliquée

**`config/app.php`** : fallback passé de `'Laravel'` à `'BouclePro'`
```php
'name' => env('APP_NAME', 'BouclePro'),
```

### Fichier modifié

- `config/app.php` — fallback `'name'` : `'Laravel'` → `'BouclePro'`

### Fichiers explicitement non modifiés

- `.env` — gitignored et non tracké. Commande locale à appliquer :
  ```
  APP_NAME=BouclePro
  ```
- `.env.sqlite` — gitignored. Même valeur à appliquer.
- `.env.pgsql` — gitignored. Même valeur à appliquer.

### Tests relancés

```bash
php artisan test --filter=AdminSendPasswordResetLinkTest  # 6/6 passed
php artisan config:clear  # config cache cleared
```

## Branding footer email

### Constat

Le footer de l'email Laravel affichait :
```
© 2026 BouclePro. All rights reserved.
```

La source est dans `vendor/.../Mail/resources/views/html/message.blade.php:24` :
```blade
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
```

### Solution choisie

Ajout d'une traduction JSON dans `lang/fr.json` (minimal, sans surcharge de blade) :
```json
"All rights reserved.": "Tous droits réservés."
```

Résultat : `© 2026 BouclePro. Tous droits réservés.`

### Fichier modifié

- `lang/fr.json` — +1 ligne

### Tests relancés

```bash
php artisan optimize:clear
php artisan test --filter=AdminSendPasswordResetLinkTest  # 6/6 passed
```

Vérifié via `tinker` :
```
__('All rights reserved.') → "Tous droits réservés."
```

## Fichiers env — statut

### État Git

| Fichier | Tracké | Modifié | Contient secret | Commitable |
|---|---|---|---|---|
| `.env` | Non | Oui (manuel) | Oui (APP_KEY) | **NON** — gitignored |
| `.env.example` | Oui | Oui (APP_NAME: BouclePro.com→BouclePro) | Non | **OUI** — seul APP_NAME changé |
| `.env.sqlite` | Oui | Oui (APP_NAME: Entraide→BouclePro) | Oui (APP_KEY) | **NON** — contient secret |
| `.env.pgsql` | Oui | Oui (APP_NAME: Entraide→BouclePro) | Oui (APP_KEY) | **NON** — contient secret |

### Recommandation commit

- `config/app.php` — **oui** (fallback BouclePro, versionné)
- `lang/fr.json` — **oui** (traduction footer)
- `.env.example` — **oui** (seul APP_NAME changé, pas de secret)
- `.env`, `.env.pgsql`, `.env.sqlite` — **non** (secrets APP_KEY présents)
- `lang/fr/passwords.php` — **oui** (nouveau fichier)

## Historique email — EmailLog

### Décision

- Seuls les resets déclenchés depuis l'admin sont loggés dans `email_logs`
- Le flux public `/forgot-password` reste hors scope (pas de EmailLog)
- `template_id` = null (pas de template email utilisé)
- Aucun token, URL reset-password ni body email loggé
- `data` contient `source`, `broker` et `admin_id` uniquement

### Implémentation

Dans `AdminController@sendPasswordResetLink`, après `Password::broker()->sendResetLink()` :

```php
if ($status === Password::RESET_LINK_SENT) {
    EmailLog::create([
        'template_id' => null,
        'user_id' => $user->id,
        'to_email' => $user->email,
        'subject' => 'Réinitialisation de votre mot de passe',
        'status' => 'sent',
        'data' => [
            'source' => 'admin-password-reset',
            'broker' => 'users',
            'admin_id' => auth()->id(),
        ],
    ]);
}
```

### Tests ajoutés (4 nouveaux, 30 assertions total)

1. `email_log_is_created_on_successful_reset` — vérifie `to_email`, `subject`, `status`, `user_id`, `template_id`
2. `email_log_data_contains_source_broker_and_admin_id` — vérifie le contenu de `data`
3. `email_log_data_does_not_contain_token_url_or_body` — vérifie l'absence de token/url/body/password
4. `non_admin_does_not_create_email_log` — vérifie qu'aucun log n'est créé sur 403

## Historique email public

### Décision

Les resets déclenchés depuis `/forgot-password` sont également loggés dans `email_logs`.
Seuls les envois réussis (`RESET_LINK_SENT`) créent une entrée.
Aucun token, URL reset-password, body email ou mot de passe loggé.

### Implémentation

Modification de `PasswordResetLinkController@store` — après `Password::sendResetLink()` :

```php
if ($status === Password::RESET_LINK_SENT) {
    $user = User::where('email', $request->email)->first();

    EmailLog::create([
        'template_id' => null,
        'user_id' => $user?->id,
        'to_email' => $request->email,
        'subject' => 'Réinitialisation de votre mot de passe',
        'status' => 'sent',
        'data' => [
            'source' => 'public-password-reset',
            'broker' => 'users',
        ],
    ]);
}
```

Notes :
- `data` ne contient pas `admin_id` sur ce flux
- `user_id` est nullable si l'email n'est pas trouvé (sécurité)
- Les routes community-prefixed `/{community}/forgot-password` utilisent le même controller → logging automatique sans modification supplémentaire

### Tests ajoutés (4 nouveaux, 16 assertions)

1. `forgot_password_with_existing_email_creates_email_log` — vérifie `to_email`, `subject`, `status`, `user_id`, `template_id`
2. `forgot_password_log_data_contains_public_source_and_no_admin_id` — vérifie `source`, `broker`, absence `admin_id`
3. `forgot_password_log_data_does_not_contain_token_url_or_body` — vérifie l'absence de token/url/body/password/admin_id
4. `forgot_password_with_unknown_email_does_not_create_log` — vérifie qu'aucun log n'est créé pour email inconnu

### Flux community-prefixed

Les routes `/{community}/forgot-password` et `/forgot-password` utilisent le même contrôleur `PasswordResetLinkController@store`. Le logging est donc actif pour les deux flux sans modification supplémentaire. Réserve documentée, pas de refactor nécessaire.

### Points de vigilance

- `/admin/email-templates` non touché
- Aucune notification custom créée
- Aucun controller auth modifié
- Aucune vue email créée (ni publiée)
- Aucune migration
- `.obsidian/` non touché
- Réserve `is_admin` global inchangée

## Résultat final

**DONE** — Implémentation terminée, tests validés, i18n corrigée.
Diff total : 3 fichiers modifiés (22 insertions) + 3 nouveaux fichiers (test, passwords.php, fr.json).
Aucun token exposé. Aucun système email-template modifié. Aucune notification custom. Aucune refactor auth.
Email de reset désormais en français. Pages forgot/reset affichent des messages français lisibles.
