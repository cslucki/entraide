---
task_id: TASK-205
status: DONE
owner: SUPERVISOR
branch: TASK-205-fix-admin-panel-bugs
lock:
  status: UNLOCKED
---

# TASK-205 — Fix 5 admin panel bugs (post-migration Organization)

## Status: DONE

## Description

Scoped models (Service, Transaction, ServiceRequest) had BelongsToOrganizationScope filtering data to `whereRaw('0 = 1')` on admin routes (no organization middleware), causing empty lists.

Report model had `$timestamps = false` but no `casts()` for `created_at` → `->format()` crash on views.

## Changes

### AdminController.php

- **Import:** Added `use App\Models\Scopes\BelongsToOrganizationScope;`
- **Dashboard:** `withoutGlobalScope()` on services, transactions, completed counts
- **Services list:** `withoutGlobalScope()` on `Service::withTrashed()`
- **Transactions list:** `withoutGlobalScope()` on `Transaction::with()`
- **Requests list:** `withoutGlobalScope()` on `ServiceRequest::with()`
- **destroyCategory:** `withoutGlobalScope()` on `$category->services()->count()`
- **Route model binding → manual resolution:**
  - `editService(string $service)` — resolve with `withoutGlobalScope()`
  - `updateService(Request, string $service)` — same
  - `forceDeleteService(string $id)` — already `string`, added `withoutGlobalScope()`
  - `restoreService(string $id)` — already `string`, added `withoutGlobalScope()`
  - `editRequest(string $serviceRequest)` — resolve with `withoutGlobalScope()`
  - `updateRequest(Request, string $serviceRequest)` — same
  - `closeRequest(string $serviceRequest)` — same

### Report.php

- Added `casts()` method with `'created_at' => 'datetime'`

## Post-review fix

- `destroyCategory`: added `withoutGlobalScope(BelongsToOrganizationScope::class)` on `serviceRequests()->count()` (VERIFICATOR gap mineur)

## Tests

813 passed, 0 failures (1729 assertions)

## Files
- `app/Http/Controllers/Admin/AdminController.php`
- `app/Models/Report.php`
