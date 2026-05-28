# TASK-158 — Pre-existing Test Failures Audit

Date: 2026-05-28 20:18:00 Europe/Paris

Branch: `TASK-158-audit-pre-existing-test-failures`

Worktree: `/home/cyril/claude-code/sites/test.laravel-T158`

## Objective

Audit the reported `~790 pass / 35 fail` PHPUnit state after the Community to Organization migration and decide whether a full test wave can be launched safely.

## Commands Run

- `php artisan test --log-junit storage/logs/task-158-phpunit.xml`
- `php artisan test tests/Feature/Admin/AdminCategoriesTest.php --filter=test_guest_cannot_access_admin_categories`
- `php -d memory_limit=-1 artisan test tests/Feature/Admin/AdminCategoriesTest.php --filter=test_guest_cannot_access_admin_categories`
- `php artisan route:list --name=admin.categories`
- `git blame -L 5,10 app/Http/Controllers/Admin/AdminController.php`

## Findings

### 1. Full PHPUnit cannot currently reach the 35 reported failures

The full suite stops early with:

```text
Fatal error: Premature end of PHP process when running Tests\Feature\Admin\AdminCategoriesTest::test_guest_cannot_access_admin_categories.
```

The same targeted test also stops with the same fatal error, including with unlimited PHP memory.

### 2. Root cause is a fatal PHP duplicate import

`php artisan route:list --name=admin.categories` reveals the real fatal error:

```text
Cannot use App\Models\Organization as Organization because the name is already in use
```

Location:

```php
// app/Http/Controllers/Admin/AdminController.php
use App\Models\Organization;
use App\Models\Organization;
```

Blame:

```text
line 8: ae2247d refactor(organization): migrate admin controllers from community_id to organization_id (T140.5E Lot B)
line 9: 5b28975 phase4(controllers): migrate controllers and routes from Community to Organization
```

This is a migration-chain regression in `5b28975`, not a business-rule test failure.

### 3. The previously reported 35 failures are not auditable until this fatal is fixed

Historical TASK records say:

- `TASK-151`: `790 pass`, `35 pre-existing failures remain (middleware/routing, hors scope)`
- `TASK-156`: `~790 pass / 35 fail`, described as pre-existing and unchanged

However, the current branch cannot reproduce that state because PHP crashes during admin route loading before the suite reaches those failures.

### 4. Known legacy references still exist, but they are not the immediate blocker

Expected post-migration compatibility references still exist:

- `current_community` compatibility bindings in middleware/support code
- `$currentCommunity` Blade fallback compatibility
- `community_id` references in tests documenting legacy behavior
- 6 service dead-code fallbacks handled separately by `TASK-157`

These are not the current PHPUnit blocker.

## Classification

| Category | Status | Notes |
|----------|--------|-------|
| Fatal PHP blocker | Confirmed | Duplicate `Organization` import in `AdminController.php` |
| Migration-related regression | Confirmed | Introduced by phase 4 commit `5b28975` |
| 35 historical failures | Not currently reproducible | Blocked by fatal PHP error |
| Environment/OOM | Not confirmed for current blocker | Unlimited memory does not change result |
| Dead service fallbacks | Separate branch | Covered by `TASK-157` |

## Recommendation

Do not launch the full test wave yet.

Next step should be a tiny fix branch for the duplicate import, then rerun the T158 audit/full suite to expose the real remaining failure list.

Suggested branch:

`TASK-159-fix-admincontroller-duplicate-organization-import`
