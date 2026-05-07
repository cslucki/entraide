# Entraide — Claude Code Guide

## Project Overview

**Entraide** is a peer-to-peer service exchange platform (troc de services) built with Laravel 13.7.
Users earn points by providing services and spend them to receive help from others.
The platform is **multi-tenant** (one community per slug, e.g. `/cpme/...`) and entirely in French.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13.7 · PHP 8.4 |
| Database | SQLite (dev) / MySQL (prod) |
| Frontend | Blade · Alpine.js · Tailwind CSS v4 |
| Reactive UI | Livewire 3 |
| Auth | Laravel Breeze (Blade + dark mode) |
| API | Laravel Sanctum (token-based REST API) |
| Image processing | `intervention/image` (avatar resize 300×300) |

## Architecture

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── AdminController.php          ← dashboard + stats
│   │   │   ├── AdminCommunityController.php ← CRUD communautés
│   │   │   ├── AdminEmailController.php     ← envoi email de test
│   │   │   ├── AdminMessageController.php   ← modération messages
│   │   │   ├── AdminMetaCommunityController.php
│   │   │   └── AdminSettingController.php   ← settings plateforme
│   │   ├── Api/
│   │   │   ├── AuthController.php
│   │   │   ├── ProfileController.php
│   │   │   ├── ServiceController.php
│   │   │   ├── ServiceRequestController.php
│   │   │   └── TransactionController.php
│   │   ├── Auth/                            ← Laravel Breeze
│   │   ├── CommunityLandingController.php   ← page d'accueil /{community}
│   │   ├── CommunityRequestController.php   ← demandes rejoindre communauté
│   │   ├── DashboardController.php
│   │   ├── ExplorerController.php
│   │   ├── FavoriteController.php
│   │   ├── HomeController.php
│   │   ├── MessageController.php
│   │   ├── PointController.php
│   │   ├── ProfileController.php
│   │   ├── ReportController.php
│   │   ├── RequestController.php
│   │   ├── ReviewController.php
│   │   ├── SearchController.php             ← recherche globale navbar
│   │   ├── ServiceController.php
│   │   ├── SitemapController.php
│   │   └── TransactionController.php
│   └── Middleware/
│       ├── AdminMiddleware.php              ← is_admin gate
│       ├── EnsureProfileComplete.php        ← verrou profil incomplet
│       ├── EnsureUserIsNotBanned.php        ← bannissement
│       └── ResolveCommunity.php            ← injecte communauté active depuis slug
├── Livewire/
│   ├── Explorer.php                        ← search/filter with #[Url]
│   └── MessageThread.php                  ← polling every 3 s
├── Models/
│   ├── Scopes/BelongsToTenantScope.php    ← filtre automatique par community_id
│   ├── Badge.php
│   ├── Category.php
│   ├── Community.php                      ← tenant principal (slug, name, customization)
│   ├── CommunityRequest.php               ← demande d'adhésion
│   ├── Favorite.php
│   ├── Message.php
│   ├── PointGuideline.php
│   ├── PointLedger.php
│   ├── Report.php
│   ├── RequestAttachment.php
│   ├── Review.php
│   ├── Service.php
│   ├── ServiceImage.php
│   ├── ServiceRequest.php
│   ├── Setting.php                        ← settings plateforme (clé/valeur)
│   ├── Skill.php
│   ├── Tag.php
│   ├── Transaction.php
│   └── User.php
└── Policies/
    ├── MessagePolicy.php
    ├── ReviewPolicy.php
    ├── ServicePolicy.php
    ├── ServiceRequestPolicy.php
    └── TransactionPolicy.php
resources/views/
├── layouts/
│   ├── app.blade.php          ← main layout (global toast notifications)
│   ├── admin.blade.php        ← admin sidebar layout
│   └── navigation.blade.php   ← navbar avec pill points + badge non-lus
├── admin/                     ← back-office (x-admin-layout)
├── livewire/                  ← explorer, message-thread
├── services/, requests/, messages/, favorites/, points/
└── profile/
tests/
├── Feature/
│   ├── Admin/                 ← AdminUsersTest, AdminCategoriesTest, AdminCommunitiesTest,
│   │                            AdminMessagesTest, AdminSettingTest, AdminUserCreateTest
│   ├── Api/                   ← AuthApiTest, ServiceApiTest, TransactionApiTest
│   ├── Livewire/              ← ExplorerTest, MessageThreadTest
│   ├── Policies/              ← Service, ServiceRequest, Transaction, Message, Review
│   ├── BadgeServiceTest.php
│   ├── BanMiddlewareTest.php
│   ├── CommunityModelTest.php
│   ├── FullExchangeFlowTest.php
│   ├── PointsSystemTest.php
│   ├── SearchControllerTest.php
│   ├── ServiceControllerTest.php
│   ├── TransactionControllerTest.php
│   ├── TransactionStateMachineTest.php
│   └── FavoriteControllerTest.php
└── database/factories/        ← all models have factories
```

## What Is Already Built

### Core MVP
- **Services CRUD** — title, description, category, skills, tags (max 5), delivery mode, points cost, status (active/paused), soft-delete
- **Service images** — up to 5 images per service, 2 MB max, thumbnail auto-généré, stored in `storage/`
- **Requests CRUD** — service requests with budget range, deadline, status (open/in_progress/closed), pièces jointes
- **Transactions** — full lifecycle (pending → accepted → buyer_done → completed), point ledger written atomically on completion
- **Messaging** — Livewire split-view, polling every 3s, system messages on state changes, unread badge, modération admin
- **Explorer** — Livewire search + category filter + delivery mode + sort (date/price/rating) + tag filter + note minimale + load more
- **Dashboard** — metrics (points earned/spent, active services, ongoing exchanges, recent messages)
- **Reviews/Ratings** — 1–5 stars after completed transaction, auto-recalculates `users.rating`
- **Favorites** — toggle + dedicated page `/favorites`
- **Points history** — append-only ledger, page `/points` avec graphique Chart.js
- **Reports** — inline form to report services, requests, or users (polymorphic)
- **Recherche globale** — navbar, résultats multi-modèles
- **Sitemap XML** dynamique + robots.txt

### User Profiles
- Avatar upload with resize to 300×300 via `intervention/image`, fallback to ui-avatars.com
- Bio (500 chars), location (city/dept), website, LinkedIn, phone, visibility
- Public profile page with active services, open requests, reviews received, stats
- Verrou si profil incomplet (`EnsureProfileComplete` middleware)

### Gamification
- Badges automatiques (BadgeService) — attribués selon les actions utilisateur
- Historique de badges sur la page profil

### Notifications email
- Email de bienvenue à l'inscription
- Notification email lors d'une nouvelle transaction
- Notification email lors d'un nouveau message
- Config mail via Resend (`.env.example` mis à jour)

### API REST (Sanctum)
- Authentification token (`/api/login`, `/api/register`)
- Endpoints : services, service requests, transactions, profil
- Tests dédiés dans `tests/Feature/Api/`

### Multi-tenant (communautés)
- Chaque communauté a un slug unique (ex: `cpme`, `bni`, `60000rebonds`)
- Routes préfixées `/{community}/...`, `ResolveCommunity` middleware
- `BelongsToTenantScope` sur `Service`, `ServiceRequest`, `Transaction` — filtre automatique
- `community_id` sur users, services, service_requests, transactions
- Page d'accueil personnalisée par communauté (`CommunityLandingController`)
- Demandes d'adhésion via `CommunityRequest`

### Admin Back Office
- Sidebar layout (`x-admin-layout`) at `/admin`
- Dashboard: stats globales + par communauté
- Users: search, filter (available/banned/admin), ban/unban, toggle admin, adjust points, créer utilisateur
- Services: list all including soft-deleted, filter by status, force-delete, restore
- Transactions: list with status filter, export CSV
- Requests: list with status filter, force-close
- Categories: full CRUD + add/remove skills inline
- Reports: review or dismiss
- Communities: CRUD, toggle active, customization (logo, couleurs)
- Messages: modération et suppression
- Settings: paramètres plateforme clé/valeur
- Email de test envoyable depuis l'admin

### Tests (142 passing + 94 pending, ~3s)
- Policies: Service, ServiceRequest, Transaction, Message, Review
- Points system: welcome bonus, exchange earned/spent, adjustment
- Transaction state machine: all transitions + terminal states
- Controllers: Service, Transaction, Favorite, Search
- Admin: Users, Categories, Communities, Messages, Settings, UserCreate
- API: Auth, Service, Transaction
- Livewire: Explorer, MessageThread
- Badge service, Ban middleware, Community model
- Full exchange flow (end-to-end)

## Database Key Points

- **All PKs are UUIDs** via `HasUuids` trait — never use `->id()`, always `->uuid('id')->primary()`
- **Point ledger** is append-only; balance is maintained on `users.points_balance` for reads
- **Soft-deleted** models: `Service` (deleted_at), `Community` (deleted_at)
- **Banned users**: `users.banned_at` timestamp (null = active)
- **Service images**: `service_images` table — max 5 per service, 2 MB each, thumbnail auto
- **User profile**: `users.bio` (500 chars), `users.location`, `users.website`, `users.linkedin`, `users.phone`, `users.visibility`
- **Multi-tenant**: `community_id` FK sur `users`, `services`, `service_requests`, `transactions`
- **Communities**: `communities.slug` (unique), `is_active`, `is_public`, customization fields
- **Settings**: table clé/valeur `settings` (model `Setting`)
- **Badges**: table `badges`, relation `user->badges()`
- **Request attachments**: table `request_attachments`

## Points System

- New user: **+100 pts** (welcome_bonus) written atomically to `point_ledger` + `users.points_balance`
- Exchange completion: buyer decremented, seller incremented inside a single `DB::transaction()`
- Reason enum: `welcome_bonus | exchange_earned | exchange_spent | adjustment`

## Transaction State Machine

```
pending → accepted → buyer_done → completed
        ↘ refused              ↘ accepted (contest)
pending/accepted → cancelled
```

## Models Note

`ServiceRequest` (not `Request`) is used to avoid collision with `Illuminate\Http\Request`.
Routes still use `/requests` prefix.

The base `Controller` uses `AuthorizesRequests` trait — call `$this->authorize()` for all policy checks.

## Routes Note

`services.show` uses `->whereUuid('service')` to prevent `/services/create` from being captured by the wildcard parameter. This constraint is critical — do not remove it.

All community-scoped routes use `Route::prefix('/{community}')` with `ResolveCommunity` middleware.

## Admin Panel

Access: users with `is_admin = true`, guarded by `AdminMiddleware`.
URL prefix: `/admin` · route name prefix: `admin.`

## Common Commands

```bash
# Development server
php artisan serve

# Fresh database with seed data
php artisan migrate:fresh --seed

# Build assets (requires Node 20+)
npm run dev          # watch mode
npm run build        # production build

# Run tests (142 passing, ~3s)
php artisan test
```

## Coding Conventions

- Controller methods return `View|RedirectResponse` type hints
- Validation happens inside controllers (no separate FormRequest classes yet)
- Tags: max 5, slug-normalized, created on the fly via `Tag::firstOrCreate()`
- Services with active transactions (pending/accepted) cannot be edited or deleted
- Admin actions never affect the currently authenticated admin (e.g. cannot remove own admin rights)
- All model factories exist in `database/factories/` — use them in tests
- Tenant scope appliqué automatiquement via `BelongsToTenantScope` — ne pas filtrer manuellement par `community_id`

## Environment

Copy `.env.example` to `.env`, set `DB_CONNECTION=sqlite`, then:

```bash
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
```
