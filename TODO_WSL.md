# TODO_WSL.md — Backlog Claude Code WSL (backend / PHP / Laravel)

> **Règle absolue :** Seul Claude Code WSL lit et modifie ce fichier.
> Avant de commencer : lire AGENTS.md et CLAUDE.md.
> Branche : `claude/TASK-XXX` depuis main à jour.
> Lancer `php artisan test` avant chaque push. 0 échec requis. PR vers main.

---

## Statuts

| Statut | Signification |
|---|---|
| `TODO` | Disponible, à prendre |
| `IN_PROGRESS` | En cours — noter la branche |
| `IN_REVIEW` | PR ouverte |
| `DONE` | Fusionné dans main |

---

## 🟢 Prêt à lancer

### TASK-016 + TASK-019 — SEO avancé + Export CSV (même branche)
- **Statut** : `IN_REVIEW`
- **Branche** : `claude/TASK-016`
- **Fichiers** :
  - `app/Http/Controllers/ServiceController.php` (méthode `show`)
  - `app/Http/Controllers/RequestController.php` (méthode `show`)
  - `app/Http/Controllers/ProfileController.php`
  - `resources/views/layouts/app.blade.php` (slot JSON-LD avant `</head>`)
  - `app/Http/Controllers/TransactionController.php` (méthode `exportCsv`)
  - `routes/web.php` (route export AVANT la route show)

**TASK-016 — OG meta tags + JSON-LD schema.org :**
  - Passer `$ogTitle`, `$ogDescription`, `$ogImage` depuis chaque contrôleur
    (le layout a déjà le bloc `@isset($ogTitle)` lignes ~11-13)
  - Ajouter un slot `@isset($jsonLd)` dans `app.blade.php` avant `</head>`
  - JSON-LD `Service` schema.org sur les fiches service (`name`, `description`, `provider`)
  - JSON-LD `Person` schema.org sur les profils (`name`, `url`, `description`)
  - `$ogImage` = URL complète première image du service, ou avatar. `null` si absent.
  - Ne pas toucher aux contrôleurs admin.

**TASK-019 — Export CSV historique transactions :**
  - Récupérer toutes les transactions de l'utilisateur connecté (acheteur OU vendeur)
  - Colonnes CSV : Date, Type (achat/vente), Service, Contrepartie, Points, Statut
  - `Response::streamDownload()` + `fputcsv()`
  - Nom fichier : `entraide-transactions-{Y-m-d}.csv`
  - Middleware `auth` requis
  - Route `GET /transactions/export` — placer AVANT la route `show` dans `routes/web.php`

- **Tests** : `php artisan test` — 169 passent, 0 échec
- **PR** : une seule PR pour les deux :
  `gh pr create --title "feat(TASK-016+019): SEO avancé (OG+JSON-LD) + export CSV transactions"`

### TASK-020 — Admin étendu : configuration plateforme + gestion complète utilisateurs
- **Statut** : `IN_REVIEW`
- **Branche** : `claude/TASK-020`
- **Fichiers** :
  - `database/migrations/xxx_create_settings_table.php` (à créer)
  - `app/Models/Setting.php` (à créer)
  - `app/Http/Controllers/Admin/AdminSettingController.php` (à créer)
  - `app/Http/Controllers/Admin/AdminController.php` (ajouter createUser, storeUser, changePassword)
  - `resources/views/admin/settings/index.blade.php` (à créer)
  - `resources/views/admin/users/create.blade.php` (à créer)
  - `routes/web.php` (routes admin settings + user create)
- **Description** :
  **A. Table settings (clé/valeur) :**
  - Migration : `settings` avec colonnes `key` (string, unique), `value` (text nullable)
  - Seeder : `platform_name = "Entraide"`, `platform_tagline = "Échangez vos talents"`, `maintenance_mode = "0"`
  - Model `Setting` avec méthode statique `Setting::get('platform_name')` et `Setting::set('platform_name', 'valeur')`
  - Passer `platform_name` et `platform_tagline` à toutes les vues via un View Composer ou middleware (config/app.php)

  **B. Page admin "Configuration" :**
  - Route `GET /admin/settings` → formulaire avec les paramètres modifiables
  - Route `POST /admin/settings` → sauvegarde
  - Champs : nom plateforme, tagline, mode maintenance (toggle)

  **C. Créer un utilisateur depuis l'admin :**
  - Route `GET /admin/users/create` → formulaire (nom, email, mot de passe, is_admin, points de départ)
  - Route `POST /admin/users` → création + écriture welcome_bonus dans point_ledger si points > 0

  **D. Changer le mot de passe d'un utilisateur :**
  - Route `POST /admin/users/{user}/password` → formulaire simple (nouveau mot de passe, confirmation)
  - Hash bcrypt, pas de validation de l'ancien mot de passe (c'est l'admin)
- **Tests** : `php artisan test` — 0 échec
- **Quand terminé** : mettre `IN_REVIEW` → `gh pr create --title "feat(TASK-020): admin étendu — settings plateforme + créer user + changer mdp"`


### TASK-021 — Admin : modération des messages
- **Statut** : `IN_REVIEW`
- **Branche** : `claude/TASK-021`
- **Fichiers** :
  - `app/Http/Controllers/Admin/AdminMessageController.php` (à créer)
  - `resources/views/admin/messages/index.blade.php` (à créer)
  - `resources/views/admin/messages/show.blade.php` (à créer)
  - `routes/web.php` (ajouter routes admin messages)
  - `tests/Feature/Admin/AdminMessagesTest.php` (à créer)
  - `tests/e2e/smoke.spec.js` (ajouter tests Playwright)

- **Description** :

  **A. Liste des messages (`GET /admin/messages`) :**
  - Afficher les 50 derniers messages tous utilisateurs confondus, paginés (50/page)
  - Colonnes : date, expéditeur, destinataire (via la transaction liée), contenu (tronqué à 100 chars), actions
  - Filtres (GET params) : `user` (nom ou email, expéditeur OU destinataire), `date_from`, `date_to`, `search` (mot-clé dans le contenu)
  - Tri par date décroissante par défaut

  **B. Détail d'un message (`GET /admin/messages/{message}`) :**
  - Afficher le message dans son contexte : 5 messages précédents et suivants dans la même transaction
  - Afficher expéditeur, destinataire, date, contenu complet
  - Lien vers la transaction liée

  **C. Suppression (`DELETE /admin/messages/{message}`) :**
  - Hard delete — suppression définitive en base
  - Redirection vers la liste avec message flash de confirmation
  - Formulaire avec token CSRF + method DELETE dans les vues

- **Tests Laravel** (`tests/Feature/Admin/AdminMessagesTest.php`) :
  - Admin peut voir la liste des messages
  - Les filtres fonctionnent (par user, par date, par mot-clé)
  - Admin peut supprimer un message (vérifie qu'il n'existe plus en base)
  - Non-admin ne peut pas accéder aux routes (403)
  - `php artisan test` — 0 échec

- **Tests Playwright** (ajouter dans `tests/e2e/smoke.spec.js`) :
  - Naviguer vers `/admin/messages` et vérifier que la page charge
  - Vérifier la présence du tableau et de la pagination
  - Screenshot `admin-messages.png`

- **Quand terminé** : mettre `IN_REVIEW` dans ce fichier, puis :
  `gh pr create --title "feat(TASK-021): admin — modération et suppression des messages"`


---

## 🟡 Backlog à venir

### TASK-025 — Recherche dans les conversations
- **Statut** : `TODO`
- **Fichiers** : `app/Http/Controllers/MessageController.php`, vues messages
- **Description** : Input de recherche sur `/messages`. Filtre par nom interlocuteur
  ou contenu du dernier message.

### TASK-026 — Statistiques enrichies sur le profil
- **Statut** : `TODO`
- **Fichiers** : `app/Http/Controllers/ProfileController.php`
- **Description** : Ajouter taux de complétion des transactions et temps de réponse moyen
  dans les données passées à la vue profil public.

### TASK-027 — Documentation API OpenAPI/Swagger
- **Statut** : `TODO` (API REST déjà faite via TASK-013)
- **Fichiers** : `routes/api.php`, `openapi.yaml` ou package `darkaonline/l5-swagger`
- **Description** : Documenter tous les endpoints REST existants.

### TASK-028 — Filtre par localisation dans l'explorateur
- **Statut** : `TODO`
- **Fichiers** : `app/Livewire/Explorer.php`
- **Description** : Filtre backend par ville/département. `#[Url]` pour persister.
  Correspondance LIKE souple.

### TASK-022-route — Route /faq
- **Statut** : `TODO` (coordonner avec Jules/TASK-022)
- **Fichiers** : `routes/web.php`
- **Description** : Ajouter `Route::view('/faq', 'faq')` quand Jules a créé la vue.

---

## 🔵 Long terme / BLOCKED

### TASK-014 — Notifications temps réel (broadcast)
- **Statut** : `BLOCKED` — nécessite serveur WebSocket (Reverb ou Pusher en prod)
- **Description** : Remplacer le polling Livewire 3s par événements broadcast.

### Paiement Stripe
- **Statut** : `TODO` long terme
- **Description** : Achat de packs de points, complément argent réel, factures PDF.

### Multi-langue
- **Statut** : `TODO` long terme
- **Description** : i18n Laravel, traduction FR/EN des vues et des emails.

---

## ✅ DONE

| Tâche | Fusionné |
|---|---|
| TASK-001 Middleware bannissement `banned_at` | 2026-05-01 |
| TASK-002 Thumbnail automatique images service | 2026-05-01 |
| TASK-003 Tests Livewire Explorer + MessageThread | 2026-05-01 |
| TASK-004 Tests panneau admin (25 tests) | 2026-05-01 |
| TASK-005 Notifications email (bienvenue, transaction, message) | 2026-05-01 |
| TASK-007 Sitemap XML dynamique + robots.txt | 2026-05-01 |
| TASK-008 Marquer messages comme lus + badge navbar | 2026-05-01 |
| TASK-009 Filtre note minimum dans l'explorateur | 2026-05-01 |
| TASK-011 Recherche globale navbar | 2026-05-01 |
| TASK-013 API REST Sanctum | 2026-05-01 |
| TASK-015 Gamification badges automatiques | 2026-05-01 |
