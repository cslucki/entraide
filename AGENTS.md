# Entraide — AGENTS.md

## Project Overview

**Entraide** is a peer-to-peer service exchange platform (troc de services) built with Laravel 13.7.
Users earn points by providing services and spend them to receive help from others.
The platform is entirely in French.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13.7 · PHP 8.4 |
| Database | SQLite (dev) / MySQL (prod) |
| Frontend | Blade · Alpine.js · Tailwind CSS v4 |
| Reactive UI | Livewire 3 |
| Auth | Laravel Breeze (Blade + dark mode) |
| Image processing | `intervention/image` (avatar resize 300×300) |

---

## Architecture

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/AdminController.php   ← full back-office
│   │   ├── DashboardController.php
│   │   ├── ServiceController.php       ← handles service images upload
│   │   ├── RequestController.php
│   │   ├── TransactionController.php
│   │   ├── MessageController.php
│   │   ├── FavoriteController.php
│   │   ├── PointController.php
│   │   ├── ReportController.php
│   │   └── ReviewController.php
│   └── Middleware/AdminMiddleware.php  ← is_admin gate
├── Livewire/
│   ├── Explorer.php                    ← search/filter with #[Url]
│   └── MessageThread.php              ← polling every 3 s
├── Models/                             ← all use HasUuids (UUID PKs)
│   ├── User, Category, Skill, Tag, PointGuideline
│   ├── Service, ServiceImage, ServiceRequest
│   ├── Transaction, PointLedger, Message
│   ├── Review, Favorite, Report, Badge
└── Policies/
    ├── ServicePolicy, ServiceRequestPolicy
    ├── TransactionPolicy, MessagePolicy, ReviewPolicy
resources/views/
├── layouts/
│   ├── app.blade.php          ← main layout (global toast notifications)
│   ├── admin.blade.php        ← admin sidebar layout
│   └── navigation.blade.php   ← navbar with points pill + unread badge
├── admin/                     ← back-office views (x-admin-layout)
├── livewire/                  ← explorer, message-thread
├── services/, requests/, messages/, favorites/, points/
└── profile/
tests/
├── Feature/
│   ├── Policies/              ← Service, ServiceRequest, Transaction, Message, Review
│   ├── Admin/                 ← AdminUsersTest, AdminCategoriesTest
│   ├── Api/                   ← API REST tests
│   ├── Livewire/              ← ExplorerTest, MessageThreadTest
│   ├── FullExchangeFlowTest   ← end-to-end
│   ├── PointsSystemTest, TransactionStateMachineTest
│   └── ServiceControllerTest, TransactionControllerTest, FavoriteControllerTest
└── database/factories/        ← all models have factories
```

---

## Database Key Points

- **All PKs are UUIDs** via `HasUuids` trait — never use `->id()`, always `->uuid('id')->primary()`
- **Point ledger** is append-only; balance is maintained on `users.points_balance` for reads
- **Soft-deleted** models: `Service` (deleted_at)
- **Banned users**: `users.banned_at` timestamp (null = active)
- **Service images**: `service_images` table — max 5 per service, 2 MB each, stored in `storage/`
- **User profile**: `users.bio` (500 chars), `users.location` (city/dept)

---

## Points System

- New user: **+100 pts** (welcome_bonus) written atomically to `point_ledger` + `users.points_balance`
- Exchange completion: buyer decremented, seller incremented inside a single `DB::transaction()`
- Reason enum: `welcome_bonus | exchange_earned | exchange_spent | adjustment`

---

## Transaction State Machine

```
pending → accepted → buyer_done → completed
        ↘ refused              ↘ accepted (contest)
pending/accepted → cancelled
```

---

## Models Note

`ServiceRequest` (not `Request`) is used to avoid collision with `Illuminate\Http\Request`.
Routes still use `/requests` prefix.

The base `Controller` uses `AuthorizesRequests` trait — call `$this->authorize()` for all policy checks.

## Routes Note

`services.show` uses `->whereUuid('service')` to prevent `/services/create` from being captured by
the wildcard parameter. This constraint is critical — do not remove it.

---

## Admin Panel

Access: users with `is_admin = true`, guarded by `AdminMiddleware`.
URL prefix: `/admin` · route name prefix: `admin.`

Admin can: view stats, manage users (ban/unban, points adjustment), manage services/transactions/
requests, manage categories & skills, review reports.

---

## Common Commands

```bash
php artisan serve                        # dev server
php artisan migrate:fresh --seed         # reset DB
npm run dev                              # watch assets
npm run build                            # prod build
php artisan test                         # 169 tests, ~4s
```

---

## Coding Conventions

- Controller methods return `View|RedirectResponse` type hints
- Validation inside controllers (no separate FormRequest classes yet)
- Tags: max 5, slug-normalized, `Tag::firstOrCreate()`
- Services with active transactions (pending/accepted) cannot be edited or deleted
- Admin actions never affect the currently authenticated admin
- All model factories exist in `database/factories/` — use them in tests

---

## Multi-Agent Workflow

Ce projet est développé par plusieurs IA en parallèle. Chaque agent a son propre fichier TODO.

### Fichiers de référence

| Fichier | Rôle | Qui écrit |
|---|---|---|
| `CLAUDE.md` | Description projet, archi, conventions | Orchestrateur / humain uniquement |
| `AGENTS.md` | Ce fichier — rôles et règles | Orchestrateur / humain uniquement |
| `TASKS.md` | Tableau de bord global (statuts, PRs) | Orchestrateur uniquement |
| `TODO_Jules.md` | Backlog Jules (frontend) | **Jules uniquement** |
| `TODO_WSL.md` | Backlog Claude Code WSL (backend) | **Claude Code WSL uniquement** |
| `TODO_ClaudeOnline.md` | Backlog Claude Code en ligne (rare) | **Claude Code en ligne uniquement** |
| `TODO_OpenCode.md` | Backlog OpenCode (futur) | **OpenCode uniquement** |

**Règle absolue : chaque agent ne modifie QUE son propre fichier TODO.**
Cela élimine tous les conflits de merge.

---

### Rôles par agent

| Agent | Domaine | Fichier TODO |
|---|---|---|
| **Jules** | Frontend, vues Blade, Alpine.js, CSS, Chart.js, SEO vues | `TODO_Jules.md` |
| **Claude Code WSL** | Backend PHP/Laravel, controllers, migrations, tests, routes | `TODO_WSL.md` |
| **Claude Code en ligne** | Architecture, backup (rarement utilisé) | `TODO_ClaudeOnline.md` |
| **OpenCode** | Futur | `TODO_OpenCode.md` |
| **Claude Cowork** | Orchestration, merge PRs, validation, mise à jour TASKS.md | — |

---

### Workflow obligatoire pour chaque agent

#### Avant de commencer
1. Lire **son fichier TODO** (ex : `TODO_Jules.md` pour Jules)
2. Lire `CLAUDE.md` pour les conventions techniques
3. Prendre une tâche en statut `TODO`
4. Mettre la tâche en `IN_PROGRESS` dans **son fichier TODO** (noter la branche)
5. Créer la branche : `git checkout -b <agent>/TASK-XXX` depuis un `main` à jour

#### Pendant le travail
- Toucher **uniquement les fichiers listés** dans la tâche
- Si d'autres fichiers sont nécessaires → noter l'écart dans le PR body

#### Quand la tâche est terminée
1. Mettre la tâche en `IN_REVIEW` dans **son fichier TODO**
2. Ouvrir une PR vers `main` avec un titre clair
3. Ne jamais pusher directement sur `main`

#### Conventions de branches
| Agent | Préfixe |
|---|---|
| Claude Code WSL | `claude/TASK-XXX` |
| Jules | `jules/TASK-XXX` |
| OpenCode | `opencode/TASK-XXX` |

---

### Pourquoi des fichiers TODO séparés ?
- Un seul fichier partagé + deux agents = conflit de merge garanti
- Chaque agent écrit uniquement dans son fichier → zéro conflit
- L'orchestrateur (Claude Cowork) maintient `TASKS.md` comme vue globale

---

## Environment

Copy `.env.example` to `.env`, set `DB_CONNECTION=sqlite`, then:

```bash
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
```
