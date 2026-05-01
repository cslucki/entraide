# TASKS.md — Entraide Task Board

> **Tableau de coordination multi-agents.**
> Toute IA doit lire ce fichier avant de commencer, réclamer une tâche, puis ouvrir une PR vers `main`.

---

## Règles de coordination

### Avant de commencer une tâche
1. Vérifier que la tâche est bien `TODO` (jamais `IN_PROGRESS` ou `DONE`)
2. Modifier ce fichier : mettre `IN_PROGRESS`, renseigner `Agent` et `Branche`
3. Créer la branche : `git checkout -b <agent>/<TASK-ID>` depuis un `main` à jour
4. Pusher immédiatement la mise à jour de TASKS.md pour signaler l'occupation

### Pendant le travail
- Ne toucher **que les fichiers listés** dans le champ `Fichiers` de la tâche
- Si d'autres fichiers sont nécessaires → noter l'écart dans `Notes`

### Quand la tâche est terminée
1. Mettre le statut `IN_REVIEW`
2. Ouvrir une Pull Request vers `main`
3. Décrire les changements dans la PR

### Après la fusion dans main
- Le statut passe à `DONE` (fait par l'humain ou l'IA qui a fusionné)

### ⚠️ Règle absolue
**Ne jamais pousser directement sur `main`.** Toujours passer par une branche + PR.

---

## Convention de nommage des branches

| Agent | Préfixe de branche |
|---|---|
| Claude Code | `claude/TASK-XXX` |
| Google Jules | `jules/TASK-XXX` |
| Autre IA | `ai/TASK-XXX` |
| Humain | `human/TASK-XXX` |

---

## Domaines de fichiers (éviter les conflits)

Pour minimiser les conflits, chaque tâche est associée à un **domaine** :

| Domaine | Fichiers typiques |
|---|---|
| **Backend** | `app/Http/Controllers/`, `app/Models/`, `app/Policies/`, `database/` |
| **Frontend** | `resources/views/`, `resources/css/`, `resources/js/` |
| **Tests** | `tests/`, `database/factories/` |
| **Routes** | `routes/web.php` (modifier prudemment — source de conflits fréquents) |
| **Config** | `config/`, `.env.example`, `composer.json` |
| **Docs** | `CLAUDE.md`, `AGENTS.md`, `TODO3.md`, `TASKS.md`, `README.md` |

---

## Statuts possibles

| Statut | Signification |
|---|---|
| `TODO` | Disponible — peut être réclamé |
| `IN_PROGRESS` | En cours — **ne pas toucher** |
| `IN_REVIEW` | PR ouverte, en attente de fusion |
| `DONE` | Fusionné dans main |
| `BLOCKED` | Bloqué par une dépendance, voir Notes |

---

---

# 🔴 CRITIQUE

---

### TASK-001 — Vérification du bannissement à la connexion
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-001` → [PR #2](https://github.com/cslucki/entraide/pull/2)
- **Priorité** : 🔴 Critique
- **Domaine** : Backend
- **Fichiers** :
  - `app/Http/Middleware/EnsureUserIsNotBanned.php` (à créer)
  - `bootstrap/app.php`
  - `tests/Feature/BanMiddlewareTest.php` (à créer)
- **Description** : Les utilisateurs avec `banned_at` non null peuvent actuellement se connecter. Créer un middleware qui vérifie `banned_at` après authentification et déconnecte + redirige avec un message d'erreur.
- **Notes** : —

---

### TASK-002 — Thumbnail automatique des images de service
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-002` → [PR #4](https://github.com/cslucki/entraide/pull/4)
- **Priorité** : 🔴 Critique
- **Domaine** : Backend
- **Fichiers** :
  - `app/Http/Controllers/ServiceController.php`
  - `app/Jobs/GenerateServiceThumbnail.php` (à créer)
- **Description** : À l'upload d'une image de service, générer automatiquement une version réduite (ex : 800×600 max) via `intervention/image`. Stocker sous `thumbnails/<original_path>`.
- **Notes** : `intervention/image` est déjà installé (utilisé pour les avatars).

---

### TASK-003 — Tests des composants Livewire
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-003` → [PR #3](https://github.com/cslucki/entraide/pull/3)
- **Priorité** : 🔴 Critique
- **Domaine** : Tests
- **Fichiers** :
  - `tests/Feature/Livewire/ExplorerTest.php` (à créer)
  - `tests/Feature/Livewire/MessageThreadTest.php` (à créer)
- **Description** : Tester les composants Livewire Explorer (recherche, filtres, charger plus) et MessageThread (envoi de message, polling, marquage lu).
- **Notes** : Utiliser `Livewire::test()`. Les factories existent pour tous les modèles.

---

### TASK-004 — Tests du panneau admin
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-004` → [PR #5](https://github.com/cslucki/entraide/pull/5)
- **Priorité** : 🔴 Critique
- **Domaine** : Tests
- **Fichiers** :
  - `tests/Feature/Admin/AdminUsersTest.php` (à créer)
  - `tests/Feature/Admin/AdminCategoriesTest.php` (à créer)
- **Description** : Tester les actions admin : ban/unban, ajustement de points (vérifier l'écriture dans point_ledger), CRUD catégories, accès refusé aux non-admins.
- **Notes** : Utiliser `actingAs($admin)` avec un user `is_admin = true`.

---

---

# 🟡 IMPORTANT

---

### TASK-005 — Notifications email (infrastructure)
- **Statut** : `TODO`
- **Agent** : —
- **Branche** : —
- **Priorité** : 🟡 Important
- **Domaine** : Backend + Config
- **Fichiers** :
  - `app/Notifications/WelcomeNotification.php` (à créer)
  - `app/Notifications/TransactionStatusChanged.php` (à créer)
  - `app/Notifications/NewMessageReceived.php` (à créer)
  - `resources/views/emails/` (dossier à créer)
  - `config/mail.php`
  - `.env.example`
- **Description** : Configurer le mailer (log en dev). Envoyer : mail de bienvenue à l'inscription, mail quand une transaction change de statut, mail quand un nouveau message est reçu.
- **Notes** : Utiliser les Notifications Laravel (pas les Mailables). Déclencher depuis TransactionController et MessageThread.
- **Dépendances** : Aucune.

---

### TASK-006 — SEO : meta tags dynamiques
- **Statut** : `IN_PROGRESS`
- **Agent** : Jules
- **Branche** : `jules/TASK-006`
- **Priorité** : 🟡 Important
- **Domaine** : Frontend
- **Fichiers** :
  - `resources/views/layouts/app.blade.php`
  - `resources/views/services/show.blade.php`
  - `resources/views/requests/show.blade.php`
  - `resources/views/profile/show.blade.php`
- **Description** : Passer `$ogTitle`, `$ogDescription`, `$ogImage` depuis chaque contrôleur vers le layout. Le layout app.blade.php a déjà le bloc `@isset($ogTitle)` prêt.
- **Notes** : Ne pas toucher aux contrôleurs admin.

---

### TASK-007 — SEO : Sitemap XML + Robots.txt
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-007` → [PR #6](https://github.com/cslucki/entraide/pull/6)
- **Priorité** : 🟡 Important
- **Domaine** : Backend + Config
- **Fichiers** :
  - `app/Http/Controllers/SitemapController.php` (à créer)
  - `routes/web.php`
  - `public/robots.txt` (à créer)
- **Description** : Route `/sitemap.xml` qui génère dynamiquement le sitemap avec les services actifs et les profils publics. Fichier `robots.txt` statique.
- **Notes** : Pas de package externe — générer le XML à la main avec Blade ou une simple réponse.

---

### TASK-008 — Messagerie : marquer comme lu
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-008` → [PR #7](https://github.com/cslucki/entraide/pull/7)
- **Priorité** : 🟡 Important
- **Domaine** : Backend + Frontend
- **Fichiers** :
  - `app/Livewire/MessageThread.php`
  - `resources/views/livewire/message-thread.blade.php`
- **Description** : Quand un utilisateur ouvre une conversation, marquer tous les messages non lus (`read_at = now()`) de l'autre participant. Le badge navbar doit se mettre à jour.
- **Notes** : La colonne `read_at` existe déjà sur la table `messages`.

---

---

# 🟢 CONFORT / UX

---

### TASK-009 — Explorateur : filtre par note minimum
- **Statut** : `IN_PROGRESS`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-009`
- **Priorité** : 🟢 Confort
- **Domaine** : Backend + Frontend (Livewire)
- **Fichiers** :
  - `app/Livewire/Explorer.php`
  - `resources/views/livewire/explorer.blade.php`
- **Description** : Ajouter un filtre "Note minimum" (1 à 5 étoiles) dans l'explorateur Livewire. Filtrer les services dont le propriétaire a `rating >= $minRating`.
- **Notes** : Utiliser `#[Url]` pour persister le filtre dans l'URL.

---

### TASK-010 — Dark mode toggle persistant
- **Statut** : `IN_PROGRESS`
- **Agent** : Jules
- **Branche** : `jules/TASK-010`
- **Priorité** : 🟢 Confort
- **Domaine** : Frontend
- **Fichiers** :
  - `resources/views/layouts/navigation.blade.php`
  - `resources/js/app.js`
- **Description** : Le dark mode Tailwind est déjà fonctionnel (classe `dark` sur `<html>`). Ajouter un bouton toggle dans la navbar qui sauvegarde la préférence dans `localStorage` et l'applique au chargement.
- **Notes** : Ne pas modifier `app.blade.php` — ajouter le script dans `app.js`.

---

### TASK-011 — Recherche globale dans la navbar
- **Statut** : `IN_REVIEW`
- **Agent** : Claude Code
- **Branche** : `claude/TASK-011`
- **Priorité** : 🟢 Confort
- **Domaine** : Backend + Frontend
- **Fichiers** :
  - `app/Http/Controllers/SearchController.php` (à créer)
  - `resources/views/layouts/navigation.blade.php`
  - `routes/web.php`
- **Description** : Input de recherche dans la navbar qui renvoie vers `/search?q=...` — résultats : services, demandes, utilisateurs (max 5 par catégorie).
- **Notes** : Page de résultats simple, pas de Livewire nécessaire.

---

### TASK-012 — Graphique historique des points
- **Statut** : `IN_PROGRESS`
- **Agent** : Jules
- **Branche** : `jules/TASK-012`
- **Priorité** : 🟢 Confort
- **Domaine** : Frontend
- **Fichiers** :
  - `resources/views/points/index.blade.php`
- **Description** : Ajouter un graphique linéaire du solde de points dans le temps sur la page `/points`. Utiliser Chart.js (CDN) ou une simple SVG générée côté serveur.
- **Notes** : Les données viennent du `point_ledger` déjà chargé par `PointController`.

---

---

# 🔵 LONG TERME

---

### TASK-013 — API REST (Sanctum)
- **Statut** : `TODO`
- **Agent** : —
- **Branche** : —
- **Priorité** : 🔵 Long terme
- **Domaine** : Backend
- **Fichiers** :
  - `routes/api.php`
  - `app/Http/Controllers/Api/` (dossier à créer)
- **Description** : Endpoints REST authentifiés par Sanctum pour services, requests, transactions, profile. Rate limiting par token.
- **Notes** : Installer `laravel/sanctum` si pas déjà présent. Ne pas modifier les controllers web existants.

---

### TASK-014 — Notifications temps réel (broadcast)
- **Statut** : `BLOCKED`
- **Agent** : —
- **Branche** : —
- **Priorité** : 🔵 Long terme
- **Domaine** : Backend + Config
- **Fichiers** :
  - `app/Events/`
  - `config/broadcasting.php`
- **Description** : Remplacer le polling Livewire (3s) par des événements broadcast (Pusher, Reverb, ou SSE).
- **Notes** : **Bloqué** — nécessite un serveur WebSocket (Reverb ou Pusher). À faire après déploiement prod.

---

### TASK-015 — Gamification (badges)
- **Statut** : `TODO`
- **Agent** : —
- **Branche** : —
- **Priorité** : 🔵 Long terme
- **Domaine** : Backend + Frontend
- **Fichiers** :
  - `database/migrations/xxx_create_badges_table.php` (à créer)
  - `app/Models/Badge.php` (à créer)
  - `app/Services/BadgeService.php` (à créer)
  - `resources/views/profile/show.blade.php`
- **Description** : Badges automatiques déclenchés à la complétion d'échanges, à la publication de services, etc. Affichage sur le profil public.
- **Notes** : Déclencher via observer sur Transaction (event `completed`).

---

---

## ✅ DONE

| ID | Tâche | Agent | Fusionné |
|---|---|---|---|
| — | MVP complet (services, transactions, messagerie) | Claude Code | 2026-04-28 |
| — | Système de notation | Claude Code | 2026-04-29 |
| — | Favoris + historique points + signalements | Claude Code | 2026-04-29 |
| — | Back office admin complet | Claude Code | 2026-04-30 |
| — | Avatar upload + redimensionnement | Jules | 2026-04-30 |
| — | Bio + localisation profil | Jules | 2026-04-30 |
| — | Images de service (max 5, 2Mo) | Jules | 2026-04-30 |
| — | 74 tests + factories | Laurent | 2026-04-30 |
| — | Fix route conflict `/services/create` | Laurent | 2026-04-30 |
