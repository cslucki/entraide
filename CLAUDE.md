# Entraide — Claude Code Guide

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

## Architecture

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Admin/AdminController.php   ← full back-office
│   │   ├── Auth/                       ← Breeze authentication controllers
│   │   ├── DashboardController.php
│   │   ├── ExplorerController.php      ← landing page / search wrapper
│   │   ├── FavoriteController.php
│   │   ├── HomeController.php          ← simple landing redirect
│   │   ├── MessageController.php
│   │   ├── PointController.php
│   │   ├── ProfileController.php       ← user profile management
│   │   ├── ReportController.php
│   │   ├── RequestController.php
│   │   ├── ReviewController.php
│   │   ├── ServiceController.php
│   │   └── TransactionController.php
│   └── Middleware/AdminMiddleware.php  ← is_admin gate
├── Livewire/
│   ├── Explorer.php                    ← search/filter with #[Url]
│   └── MessageThread.php              ← polling every 3 s
├── Models/                             ← all use HasUuids (UUID PKs)
│   ├── User, Category, Skill, Tag, PointGuideline
│   ├── Service, ServiceRequest
│   ├── Transaction, PointLedger, Message
│   ├── Review, Favorite, Report
└── Policies/
    ├── ServicePolicy, ServiceRequestPolicy
    ├── TransactionPolicy, MessagePolicy, ReviewPolicy
resources/views/
├── layouts/
│   ├── app.blade.php          ← main layout (global toast notifications)
│   └── navigation.blade.php   ← navbar with points pill + unread badge
├── components/
│   └── admin-layout.blade.php ← admin sidebar layout (x-admin-layout)
├── admin/                     ← back-office views
├── livewire/                  ← explorer, message-thread
├── services/, requests/, messages/, favorites/, points/
└── profile/
```

## Database Key Points

- **All PKs are UUIDs** via `HasUuids` trait — never use `->id()`, always `->uuid('id')->primary()`
- **Point ledger** is append-only; balance is maintained on `users.points_balance` for reads
- **Soft-deleted** models: `Service` (deleted_at)
- **Banned users**: `users.banned_at` timestamp (null = active)

## Points System

- New user: **+100 pts** (welcome_bonus) written atomically to `point_ledger` + `users.points_balance`
- Exchange completion: buyer decremented, seller incremented inside a single `DB::transaction()`
- Reason enum: `welcome_bonus | exchange_earned | exchange_spent | adjustment`

## Transaction State Machine

```
pending → accepted → buyer_done → completed
        ↘ refused
pending/accepted → cancelled
```

## Models Note

`ServiceRequest` (not `Request`) is used to avoid collision with `Illuminate\Http\Request`.  
Routes still use `/requests` prefix.

## Admin Panel

Access: users with `is_admin = true`, guarded by `AdminMiddleware`.  
URL prefix: `/admin` · route name prefix: `admin.`

Admin can:
- View platform stats (users, services, transactions, points in circulation, pending reports)
- List / search users, toggle availability, toggle admin, ban / unban
- Adjust user points (writes to point_ledger with reason `adjustment`)
- List all services, force-delete a service
- List all transactions
- List all service requests
- Manage categories (CRUD) and see associated skills
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

# Run tests (if added)
php artisan test
```

## Coding Conventions

- Controller methods return `View|RedirectResponse` type hints
- Validation happens inside controllers (no separate FormRequest classes yet)
- Tags: max 5, slug-normalized, created on the fly via `Tag::firstOrCreate()`
- Services with active transactions (pending/accepted) cannot be edited or deleted
- Admin actions never affect the currently authenticated admin (e.g. cannot remove own admin rights)

## Environment

Copy `.env.example` to `.env`, set `DB_CONNECTION=sqlite`, then:

```bash
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
```
