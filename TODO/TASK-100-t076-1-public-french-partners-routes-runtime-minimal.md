---
task_id: TASK-100
title: t076-1-public-french-partners-routes-runtime-minimal

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-100-t076-1-public-french-partners-routes-runtime-minimal

priority: MEDIUM

created_at: 2026-05-18 07:55:40 Europe/Paris
updated_at: 2026-05-18 11:13:53 Europe/Paris

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

Implement the minimal French public partner surface for T076.1:

- add `/partenaires`
- add `/partenaires/demande`
- redirect `/boucles/creer` explicitly to `/partenaires/demande`
- keep `/boucles` as the canonical French public route for real Boucles
- avoid tenant middleware, DB, Partner model, ChatLoop, `/loops`, `/partners`, `/organization`, `/explorer`, and broad refactors

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-05-18 07:55:40 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-100-t076-1-public-french-partners-routes-runtime-minimal

Status:
IN_PROGRESS

## 2026-05-18 10:37:17 Europe/Paris

Implemented minimal public French partner routes and views.

Routes added/changed:

- `GET /partenaires` → `partenaires.index`
- `GET /partenaires/demande` → `partenaires.request.create`
- `POST /partenaires/demande` → `partenaires.request.store`
- `GET /boucles/creer` → explicit redirect to `/partenaires/demande`

Confirmed `/boucles` remains routed to `boucles.index` and does not redirect to `/partenaires`.

Kept scope minimal:

- no tenant middleware changes
- no DB migration
- no Partner model
- no ChatLoop changes
- no `/loops`, `/partners`, `/organization`, `/explorer`, `/organisation`, or `/organisation/demande` implementation

Modified files:

- `routes/web.php`
- `app/Http/Controllers/HomeController.php`
- `app/Http/Controllers/CommunityRequestController.php`
- `resources/views/partenaires/index.blade.php`
- `resources/views/community-requests/create.blade.php`
- `resources/views/boucles/index.blade.php`
- `resources/views/home.blade.php`
- `tests/Feature/PublicFrenchPartnersRoutesTest.php`

Validation notes:

- initial targeted test run showed `/boucles` requires an active default organization under the existing runtime; tests were adjusted to create an active organization instead of changing middleware
- `npm run build` succeeded; generated ignored assets were not committed
- screenshots captured under ignored `ai/playwright/screenshots/`

## 2026-05-18 11:02:37 Europe/Paris

Applied OPENAI REQUEST CHANGES minimal corrective patch.

Corrections applied:

- removed partner CTA from `/boucles`; `/boucles` no longer points to `/partenaires` or `/partenaires/demande`
- replaced `/boucles` top block with neutral non-partner wording reserving the page for future Boucles
- replaced public partner wording `Communautés` with `Collectifs` on `/partenaires`
- changed `PublicFrenchPartnersRoutesTest` to use `Organization::factory()` instead of importing `Community`

Scope preserved:

- no `/loops`, `/partners`, `/organization`, or `/explorer` changes
- no tenant middleware changes
- no Partner model
- no DB migration
- no ChatLoop changes

Validation after corrective patch:

- `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 4 tests / 8 assertions
- `php artisan test` — PASS, 664 tests / 1430 assertions
- `npm run build` — PASS
- Playwright quick validation — PASS on `/partenaires`, `/partenaires/demande`, and `/boucles` in desktop and mobile viewports; no console warnings/errors observed

## 2026-05-18 11:13:53 Europe/Paris

Applied second OPENAI REQUEST CHANGES minimal corrective patch.

Corrections applied:

- neutralized `/boucles` fully by removing the legacy `$communities` grid from `resources/views/boucles/index.blade.php`
- removed all rendered legacy Community entries from `/boucles`
- removed all `community.home` links from `/boucles`
- kept `/boucles` as a normal non-redirecting page with neutral wording reserved for future real Boucles
- added targeted assertions proving `/boucles` does not render an Organization fixture name and does not include the old `community.home` URL

Scope preserved:

- no routes changes
- no HomeController changes
- no CommunityRequestController changes
- no `/partenaires`, `/partenaires/demande`, `/loops`, `/partners`, `/organization`, or `/explorer` changes
- no tenant middleware changes
- no models, migrations, or ChatLoop changes

Validation after second corrective patch:

- `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 4 tests / 11 assertions
- `php artisan test` — PASS, 664 tests / 1433 assertions
- `npm run build` — PASS
- Playwright quick validation — PASS on `/boucles`, `/partenaires`, and `/partenaires/demande` in desktop and mobile viewports; `/boucles` console check showed 0 warnings and 0 errors

# Handoffs

No handoff. Task completed by OPENCODE.

# Tests

- [x] feature tests
- [x] browser validation
- [x] responsive validation
- [x] console inspection
- [x] tenant validation

---

# Test Results

- `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 4 tests / 8 assertions
- `php artisan test tests/Feature/T07411RoutesTenantSafetyTest.php` — PASS, 21 tests / 34 assertions
- `php artisan test` — PASS, 664 tests / 1430 assertions
- `npm run build` — PASS
- Playwright browser validation — PASS on `http://test.laravel/partenaires` and `http://test.laravel/partenaires/demande`
- Playwright responsive validation — PASS on mobile viewport 390x844
- Playwright dark mode validation — PASS on `/partenaires`
- Playwright console inspection — PASS, no warnings or errors observed
- OPENAI REQUEST CHANGES patch: `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 4 tests / 8 assertions
- OPENAI REQUEST CHANGES patch: `php artisan test` — PASS, 664 tests / 1430 assertions
- OPENAI REQUEST CHANGES patch: `npm run build` — PASS
- OPENAI REQUEST CHANGES patch: Playwright quick validation — PASS on `/partenaires`, `/partenaires/demande`, and `/boucles` desktop/mobile; no warnings/errors observed
- Second OPENAI REQUEST CHANGES patch: `php artisan test tests/Feature/PublicFrenchPartnersRoutesTest.php` — PASS, 4 tests / 11 assertions
- Second OPENAI REQUEST CHANGES patch: `php artisan test` — PASS, 664 tests / 1433 assertions
- Second OPENAI REQUEST CHANGES patch: `npm run build` — PASS
- Second OPENAI REQUEST CHANGES patch: Playwright quick validation — PASS on `/boucles`, `/partenaires`, and `/partenaires/demande` desktop/mobile; `/boucles` console check showed 0 warnings and 0 errors

---

# Review Notes

T076.1 scope respected. Partner public surface now uses French URLs and visible CTA text `Devenir partenaire`. `/boucles` remains a distinct public Boucles route. English and deferred routes remain out of scope and should be handled in dedicated future tasks if needed.

OPENAI REQUEST CHANGES addressed: `/boucles` no longer exposes a partner CTA, partner public wording avoids `Communautés`, and route tests use the `Organization` compatibility model rather than importing `Community`.

Second OPENAI REQUEST CHANGES addressed: `/boucles` is now fully neutralized and no longer renders legacy `$communities`, Organization fixture entries, or `community.home` links.
