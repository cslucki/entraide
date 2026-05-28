---
task_id: TASK-162
status: DONE
owner: OpenCode
branch: TASK-162-membres-graceful-db-setup
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-162 — /membres graceful DB setup state

## Scope
Replace raw 404 on `/membres` when no Organization exists with explanatory setup page.

## Modifications
- `app/Http/Controllers/HomeController.php` — return `members.setup-required` view instead of `abort(404)`
- `resources/views/members/setup-required.blade.php` — NEW: explanatory setup page
- `app/Http/Middleware/ResolveUrlOrganization.php` — added `$passthroughNoOrgRoutes = ['membres']` so middleware lets the request reach the controller

## Tests
- `tests/Feature/MembersPageTest.php` — NEW: 5 tests

## Validation
- MembersPageTest: ✅ 5/5
- Full PHPUnit: ✅ 830/0/11 (1752 assertions)

## Scope verification
- `/echanges` and `/services` still 404 without org (regression tests)
- Only `/membres` is exempted
