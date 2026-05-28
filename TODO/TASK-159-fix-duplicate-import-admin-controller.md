---
task_id: TASK-159
status: DONE
owner: OpenCode
branch: TASK-159-fix-duplicate-import-admin-controller
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-159 — Fix duplicate import in AdminController.php

## Scope
Fix `Cannot use App\Models\Organization as Organization because the name is already in use` in `AdminController.php`.

## Modifications
- `app/Http/Controllers/Admin/AdminController.php:9` — removed duplicate `use App\Models\Organization;` (lines 8 and 9 were identical)

## Validation
- `php -l`: ✅ No syntax errors
- `php artisan route:list --name=admin.categories`: ✅ Routes OK
- `php artisan test tests/Feature/Admin/AdminCategoriesTest.php --filter=test_guest_cannot_access_admin_categories`: ✅ 1 passed

## Progress log
2026-05-28 — Error confirmed (duplicate `use` on lines 8-9). Fixed. Validated. Committed.
