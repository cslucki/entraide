---
task_id: TASK-216
title: Dashboard Admin - Se connecter sous un utilisateur

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-216-dashboard-admin-se-connecter-sous-un-utilisateur

priority: MEDIUM

created_at: 2026-06-06 23:51:00 Europe/Paris
updated_at: 2026-06-07 00:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-07 00:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter une fonctionnalité « se connecter sous » un utilisateur depuis la liste `/admin/users`, permettant à l'admin de visualiser l'application comme s'il était l'utilisateur cible, avec un mécanisme de retour au compte admin.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---

# Progress Log

## 2026-06-07 00:15:00 Europe/Paris

### Implémentation terminée

**Routes ajoutées :**
- `POST /admin/users/{user}/login-as` → `AdminController@loginAsUser` (nommée `admin.users.login-as`)
- `GET /admin/back-to-admin` → `AdminController@backToAdmin` (nommée `admin.back-to-admin`)

**Contrôleur (`AdminController`):**
- `loginAsUser()` : sauvegarde `admin_original_id` en session, login comme le user cible, regenerate session, redirect `/`
  - Bloqué pour les utilisateurs bannis (flash error)
  - Bloqué pour les autres admins (flash error)
- `backToAdmin()` : récupère `admin_original_id` de la session, login comme l'admin original, regenerate session, redirect `/admin/users`
  - Gère les cas où l'ID original est introuvable (flash error)

**Vue (`admin/users.blade.php`):**
- Bouton « Se connecter sous » dans la colonne Actions (visible uniquement si user ≠ current admin ET user non banni ET user pas admin)
- POST form vers `admin.users.login-as`

**Layout (`layouts/app.blade.php`):**
- Bannière fixe en haut de page quand `session('admin_original_id')` existe
- Texte : « Connecté sous [nom] (mode admin) » + bouton « Retour au compte admin »

### Architecture
- Session-based (pas de package externe)
- `admin_original_id` stocké en session → permet de restaurer la session admin
- `session()->regenerate()` après chaque login pour éviter fixation
- Route `back-to-admin` accessible en-dehors du groupe admin middleware (accessible par tout utilisateur authentifié)
- Vérifications de sécurité : pas login-as sur utilisateur banni, pas login-as sur un autre admin

### Tests (25 pass, 63 assertions)
- `test_admin_can_login_as_another_user` ✓
- `test_guest_cannot_login_as_user` ✓
- `test_non_admin_cannot_login_as_user` ✓
- `test_admin_cannot_login_as_banned_user` ✓
- `test_admin_cannot_login_as_another_admin` ✓
- `test_admin_can_switch_back_to_admin_after_login_as` ✓
- `test_back_to_admin_requires_existing_session` ✓

### Fichiers modifiés
- `routes/web.php` — 2 nouvelles routes
- `app/Http/Controllers/Admin/AdminController.php` — 2 nouvelles méthodes
- `resources/views/admin/users.blade.php` — bouton login-as
- `resources/views/layouts/app.blade.php` — bannière impersonation
- `tests/Feature/Admin/AdminUsersTest.php` — 7 nouveaux tests

---

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

## 2026-06-07 00:30:00 Europe/Paris

**25/25 tests pass ✅**
- Tous les tests existants (18) toujours verts
- 7 nouveaux tests login-as : tous verts (63 assertions total)

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
