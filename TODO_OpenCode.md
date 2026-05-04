# TODO_OpenCode.md — Backlog OpenCode (backend / PHP / Laravel)

> **Règle absolue :** Seul OpenCode lit et modifie ce fichier.
> Avant de commencer : lire AGENTS.md et CLAUDE.md.
> Branche : `opencode/TASK-XXX` depuis main à jour.
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

## 🚨 URGENT — Démo demain soir (exécuter dans l'ordre)

> Contexte : hébergement **Laravel Cloud** (git push to deploy · Redis natif · workers gérés).
> Domaine : **`bouclepro.com`** · Mail : **Resend** (`MAIL_MAILER=resend` + `RESEND_KEY=re_xxx`).
> Déploiement : `git push` → Laravel Cloud build automatiquement (Vite inclus, pas de FTP).
> Emails déjà codés (TASK-005) — voir TASK-038 pour la config Resend.
>
> **Base de données prod : PostgreSQL 18** (via Laravel Cloud). Dev local : SQLite (inchangé). Pas besoin d'installer PostgreSQL en local.
> Avant le premier déploiement, corriger les 3 incompatibilités SQLite → PostgreSQL dans le code :
> - `LIKE` → `ILIKE` dans `app/Livewire/Explorer.php` (recherche insensible à la casse)
> - Vérifier que les colonnes `boolean` ont bien leur cast `bool` dans les models (PostgreSQL renvoie `t`/`f`)
> - Confirmer que toutes les migrations utilisent `->uuid('id')->primary()` (jamais `->id()`)
> Mettre à jour `.env.example` avec `DB_CONNECTION=pgsql` (les vars `DB_*` sont injectées automatiquement par Laravel Cloud).

---

### TASK-030 — Migration table `communities`
- **Statut** : `TODO`
- **Branche** : `claude/TASK-030-community-multitenancy`
- **Fichiers** :
  - `database/migrations/xxxx_create_communities_table.php` (à créer)
  - `app/Models/Community.php` (à créer)
  - `database/factories/CommunityFactory.php` (à créer)
- **Description** :
  - Colonnes : `id` (UUID PK via HasUuids), `name` (string), `slug` (string, unique), `description` (text nullable), `is_active` (boolean, default true), `timestamps`
  - Contrainte : `slug` format `[a-z0-9\-]+` — valider en migration avec un index unique
  - Model `Community` avec `HasUuids`, cast `is_active` → bool
  - Méthode statique ou scope : `Community::findBySlug(string $slug): ?Community`
  - Factory : `name` = "Communauté {n}", `slug` = version slugifiée du name
- **Tests** : `php artisan test` — 0 échec

---

### TASK-031 — Migration `community_id` sur les modèles métier
- **Statut** : `TODO` (après TASK-030)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `database/migrations/xxxx_add_community_id_to_users_table.php`
  - `database/migrations/xxxx_add_community_id_to_services_table.php`
  - `database/migrations/xxxx_add_community_id_to_service_requests_table.php`
  - `database/migrations/xxxx_add_community_id_to_transactions_table.php`
  - `app/Models/User.php`, `Service.php`, `ServiceRequest.php`, `Transaction.php` (ajouter relation)
- **Description** :
  - Ajouter colonne `community_id` (UUID, nullable, foreign key → `communities.id`, onDelete SET NULL)
  - Nullable pour ne pas casser les données existantes au `migrate:fresh --seed`
  - Ajouter `belongsTo(Community::class)` sur chaque model
  - Ajouter `community_id` dans `$fillable` de chaque model
- **Tests** : `php artisan test` — 0 échec

---

### TASK-032 — Middleware `ResolveCommunity`
- **Statut** : `TODO` (après TASK-031)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `app/Http/Middleware/ResolveCommunity.php` (à créer)
  - `bootstrap/app.php` (enregistrer le middleware avec alias `community`)
- **Description** :
  - Lire `$request->route('community')` (paramètre de route, pas segment brut)
  - Si slug présent : `Community::findBySlug($slug)` → 404 si non trouvé ou `is_active = false`
  - Injecter la communauté dans le container : `app()->instance('current_community', $community)`
  - Partager avec toutes les vues : `View::share('currentCommunity', $community)`
  - Si pas de slug (routes hors communauté) : `currentCommunity = null`
- **Tests** : `php artisan test` — 0 échec

---

### TASK-033 — Global Scope `BelongsToTenant`
- **Statut** : `TODO` (après TASK-032)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `app/Models/Scopes/BelongsToTenantScope.php` (à créer)
  - `app/Models/Service.php`, `ServiceRequest.php`, `Transaction.php` (appliquer le scope)
  - **Ne pas appliquer sur `User`** — un user peut appartenir à une communauté sans que toutes ses données soient filtrées globalement
- **Description** :
  - Le scope lit `app('current_community')` (injecté par le middleware)
  - Si une communauté est active : ajoute `->where('community_id', $community->id)` à chaque query
  - Si pas de communauté active (ex : admin) : aucun filtre ajouté
  - Implémenter `ScopedBy` ou `booted()` + `addGlobalScope()` selon préférence Laravel 13
  - **Important** : le scope doit être ignorable via `withoutGlobalScope(BelongsToTenantScope::class)` pour l'admin
- **Tests** : `php artisan test` — 0 échec

---

### TASK-034 — Routes préfixées `/{community}`
- **Statut** : `TODO` (après TASK-033)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `routes/web.php`
- **Description** :
  - Créer un groupe de routes préfixé `/{community}` avec contrainte `whereIn` ou regex `[a-z0-9\-]+`
  - Ce groupe inclut : dashboard, services, explorer, requests, transactions, messages, favorites, points, profile
  - Appliquer le middleware `community` sur ce groupe
  - Les routes admin (`/admin`) restent hors du groupe (pas de scope tenant)
  - Les routes auth (login, register, password) : dupliquer dans le groupe communauté ET conserver sans préfixe pour l'admin
  - **Exemple de structure** :
    ```php
    Route::prefix('/{community}')->middleware(['web', 'community'])->where(['community' => '[a-z0-9\-]+'])->group(function () {
        // routes protégées par auth
        Route::middleware('auth')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('community.dashboard');
            // ...
        });
        // routes guest (register, login)
        Route::middleware('guest')->group(function () {
            // ...
        });
    });
    ```
  - Nommer les routes avec préfixe `community.` pour les distinguer des routes globales
  - **Critique** : conserver la contrainte `whereUuid('service')` sur services.show — l'adapter pour cohabiter avec le préfixe community
- **Tests** : `php artisan test` — 0 échec

---

### TASK-035 — Seeder communautés
- **Statut** : `TODO` (peut tourner en parallèle de TASK-032/033)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `database/seeders/CommunitySeeder.php` (à créer)
  - `database/seeders/DatabaseSeeder.php` (appeler CommunitySeeder)
- **Description** :
  - Créer 3 communautés pour la démo :
    - `slug: "cpme"`, `name: "CPME"`
    - `slug: "bni"`, `name: "BNI"`
    - `slug: "60000rebonds"`, `name: "60 000 Rebonds"`
  - Associer les users seedés existants à la communauté `cpme` par défaut
  - Associer les services seedés existants à la communauté `cpme` par défaut
  - Commande : `php artisan migrate:fresh --seed` doit fonctionner sans erreur
- **Tests** : `php artisan test` — 0 échec

---

### TASK-036 — Adapter les vues — affichage communauté active
- **Statut** : `TODO` (après TASK-034 et TASK-035)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `resources/views/layouts/app.blade.php`
  - `resources/views/layouts/navigation.blade.php`
  - `resources/views/dashboard.blade.php`
  - Formulaires de création service/demande (ajouter `community_id` hidden)
- **Description** :
  - Navbar : afficher le nom de `$currentCommunity` à côté du logo (ou sous-titre) si présent
  - Dashboard : titre "Tableau de bord — {{ $currentCommunity->name }}" si communauté active
  - Formulaire nouveau service : injecter `<input type="hidden" name="community_id" value="{{ $currentCommunity->id ?? '' }}">`
  - Formulaire nouvelle demande : idem
  - Les controlleurs doivent enregistrer `community_id` sur le modèle lors du `store()`
  - Page d'accueil de communauté (`/{community}`) : rediriger vers le dashboard si connecté, sinon vers login/register de la communauté
- **Tests** : `php artisan test` — 0 échec

---

### TASK-037 — Validation démo locale avant push Laravel Cloud
- **Statut** : `TODO` (après TASK-036)
- **Branche** : `claude/TASK-030-community-multitenancy` (même branche)
- **Fichiers** :
  - `.env.example` (documenter les variables prod Laravel Cloud)
- **Description** :
  Checklist de validation locale avant push :
  - [ ] `php artisan migrate:fresh --seed` → 0 erreur
  - [ ] `php artisan test` → 0 échec
  - [ ] `/cpme` → page dashboard ou login de la communauté CPME
  - [ ] `/bni` → idem pour BNI
  - [ ] `/60000rebonds` → idem
  - [ ] Inscription dans `/cpme/register` → user rattaché à la communauté `cpme`
  - [ ] Créer un service depuis `/cpme` → service visible uniquement dans `/cpme`
  - [ ] Explorateur `/cpme/services` → n'affiche que les services de `cpme`
  - [ ] `/admin` → pas de filtre tenant, voit tout
  Mettre à jour `.env.example` avec les variables Laravel Cloud :
  ```
  QUEUE_CONNECTION=redis
  CACHE_DRIVER=redis
  SESSION_DRIVER=redis
  MAIL_MAILER=resend
  RESEND_KEY=
  APP_ENV=production
  APP_DEBUG=false
  ```
- **PR** : `gh pr create --title "feat(TASK-030→037): multi-tenant communautés — routing /{community} pour démo BouclePro"`

---

### TASK-038 — Configuration mail Resend pour la prod
- **Statut** : `TODO` (peut tourner en parallèle de TASK-030→037)
- **Branche** : `claude/TASK-038-resend-mail`
- **Fichiers** :
  - `composer.json` (ajouter `resend/laravel`)
  - `config/mail.php` (vérifier que le mailer `resend` est déclaré)
  - `.env.example` (ajouter `RESEND_KEY=`)
  - `resources/views/emails/` (vérifier que les templates email existants sont corrects)
- **Description** :
  Les emails sont déjà implémentés (TASK-005 : bienvenue, transaction, message).
  Il manque uniquement le driver Resend :
  - `composer require resend/laravel`
  - Dans `config/mail.php`, ajouter le mailer :
    ```php
    'resend' => [
        'transport' => 'resend',
    ],
    ```
  - Vérifier que `MAIL_FROM_ADDRESS` et `MAIL_FROM_NAME` sont dans `.env.example`
  - **En prod (Laravel Cloud)** : ajouter `RESEND_KEY`, `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS=noreply@bouclepro.com` dans les variables d'environnement de l'interface Laravel Cloud
  - Tester en local avec `MAIL_MAILER=log` (pas besoin de vrai compte Resend en dev)
- **Tests** : `php artisan test` — 0 échec
- **PR** : `gh pr create --title "feat(TASK-038): intégration Resend pour envoi emails prod"`

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
