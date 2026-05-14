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

## Résultat final

**DONE** — Implémentation terminée et tests validés. Diff minimal : 3 fichiers modifiés (22 insertions) + 1 nouveau fichier de test. Aucun token exposé. Aucun système email-template modifié. Aucune notification custom. Aucune refactor auth.
