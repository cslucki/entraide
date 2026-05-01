# Entraide — Codex / Jules Guide

## Project Overview

**Entraide** is a peer-to-peer service exchange platform (troc de services) built with Laravel 11. Users earn points by providing services and spend them to receive help from others. The platform is entirely in French.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 11 · PHP 8.4 |
| Database | SQLite (dev) / MySQL (prod) |
| Frontend | Blade · Alpine.js · Tailwind CSS v4 |
| Reactive UI | Livewire 3 |
| Auth | Laravel Breeze (Blade + dark mode) |
| Image processing | `intervention/image` (avatar resize 300×300) |

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
│   ├── Review, Favorite, Report
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
│   ├── FullExchangeFlowTest   ← end-to-end: create service → transaction → complete
│   ├── PointsSystemTest, TransactionStateMachineTest
│   └── ServiceControllerTest, TransactionControllerTest, FavoriteControllerTest
└── database/factories/        ← all models have factories
```

## Database Key Points

- **All PKs are UUIDs** via `HasUuids` trait — never use `->id()`, always `->uuid('id')->primary()`
- **Point ledger** is append-only; balance is maintained on `users.points_balance` for reads
- **Soft-deleted** models: `Service` (deleted_at)
- **Banned users**: `users.banned_at` timestamp (null = active)
- **Service images**: `service_images` table — max 5 per service, 2 MB each, stored in `storage/`
- **User profile**: `users.bio` (500 chars), `users.location` (city/dept)

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

## Admin Panel

Access: users with `is_admin = true`, guarded by `AdminMiddleware`.
URL prefix: `/admin` · route name prefix: `admin.`

Admin can:
- View platform stats (users, services, transactions, points in circulation, pending reports, banned users)
- List / search users, toggle availability, toggle admin, ban / unban
- Adjust user points (writes to point_ledger with reason `adjustment`)
- List all services (including soft-deleted), force-delete, restore
- List all transactions with status filter
- List all service requests, force-close
- Manage categories (CRUD) and skills
- Review / dismiss reports

## Common Commands

```bash
# Development server
php artisan serve

# Fresh database with seed data
php artisan migrate:fresh --seed

# Build assets (requires Node 20+)
npm run dev          # watch mode
npm run build        # production build

# Run tests (74 tests, ~2.5s)
php artisan test
```

## Coding Conventions

- Controller methods return `View|RedirectResponse` type hints
- Validation happens inside controllers (no separate FormRequest classes yet)
- Tags: max 5, slug-normalized, created on the fly via `Tag::firstOrCreate()`
- Services with active transactions (pending/accepted) cannot be edited or deleted
- Admin actions never affect the currently authenticated admin (e.g. cannot remove own admin rights)
- All model factories exist in `database/factories/` — use them in tests

## Environment

Copy `.env.example` to `.env`, set `DB_CONNECTION=sqlite`, then:

```bash
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
```
