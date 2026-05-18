---
task_id: TASK-102
title: t076-3-public-english-redirect-runtime-patch

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-102-t076-3-public-english-redirect-runtime-patch

priority: MEDIUM

created_at: 2026-05-18 12:02:36 Europe/Paris
updated_at: 2026-05-18 12:18:05 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Implement the minimal T076.3 runtime patch so the remaining public English `/partners` URL is not interpreted as a legacy tenant slug and instead redirects to the canonical French public Partner route.

Scope constraints:

- runtime route patch only
- no DB change
- no migration
- no controller or view creation
- no UI redesign
- no Community to Organization migration
- no `/loops` to `/boucles` rewrite
- keep `/boucles` reserved for the real French Boucles concept
- do not touch `main` / PROD

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement minimal redirect/runtime guard
- [x] run targeted feature tests
- [x] validate `/loops` and `/boucles` route behavior via existing route safety tests
- [ ] browser validation not required for this route-only patch

---
# Progress Log


## 2026-05-18 12:02:36 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-102-t076-3-public-english-redirect-runtime-patch

Status:
IN_PROGRESS

## 2026-05-18 12:07:23 Europe/Paris

Implemented minimal public English redirect runtime patch.

Changes applied:

- Added explicit `GET /partners` redirect to `/partenaires` before the dynamic tenant slug route.
- Added `partners` to the reserved slug negative lookahead so the English public URL cannot fall through to the legacy tenant slug route.
- Added targeted Feature assertions proving `/partners` redirects to `/partenaires`, including when an active Organization fixture uses the `partners` slug.

Scope respected:

- no DB changes
- no migrations
- no controller, model, middleware, policy, Blade, or UI changes
- no new public English canonical target
- no `/partners/request` redirect added because the TASK file did not explicitly request it
- no `/loops` redirect or `/boucles` behavior change
- no `main` / PROD changes

## 2026-05-18 12:18:05 Europe/Paris

OPS finalization after OPENAI review.

Review result:

- OPENAI: APPROVE WITH NOTES
- Blocking issues: none
- Recommendation: READY FOR OPS FINALIZE

Non-blocking note:

- `Route::redirect()` currently uses Laravel's default redirect status.
- A future SEO consolidation patch may explicitly set `301` if required.
- No runtime change was made during OPS finalization.

Status moved to DONE with lock already UNLOCKED.

Modified files:

- `routes/web.php`
- `tests/Feature/PublicFrenchPartnersRoutesTest.php`
- `TODO/TASK-102-t076-3-public-english-redirect-runtime-patch.md`

# Handoffs

No handoff. Task completed by OPENCODE and ready for review after push.

Deferred / out of scope:

- `/loops` remains authenticated app runtime and was not redirected to `/boucles`.
- `/partners/request` remains unimplemented because no existing route or explicit TASK request required it.

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

- `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 6 tests / 15 assertions
- `php artisan test tests/Feature/ResolveUrlOrganizationTest.php` — PASS, 22 tests / 40 assertions
- `php artisan test tests/Feature/T07411RoutesTenantSafetyTest.php` — PASS, 21 tests / 34 assertions

Browser, responsive, and console validation were not run because this task is a route-only runtime redirect patch with no UI changes.

---

# Review Notes

OPENAI review: APPROVE WITH NOTES.

Blocking issues: none.

Recommendation: READY FOR OPS FINALIZE.

Non-blocking note: `Route::redirect()` uses Laravel's default redirect status. A future patch may explicitly set `301` if SEO consolidation requires it. No change applied during finalization.

READY FOR OPS FINALIZE.

The runtime patch is intentionally narrow: `/partners` now redirects to `/partenaires` and `partners` is reserved from legacy tenant slug capture. `/partenaires` remains the canonical public Partner route. `/boucles` remains distinct and was not transformed into a Partner route. `main` / PROD were not touched.
