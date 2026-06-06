---
task_id: TASK-214
title: Corrections design mobile page par page

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-214-corrections-design-mobile-page-par-page

priority: MEDIUM

created_at: 2026-06-06 09:34:05 Europe/Paris
updated_at: 2026-06-06 16:24:38 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-06 09:34:05 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Corriger progressivement les bugs de design mobile/PWA page par page.

Premier périmètre demandé par Cyril :
- rendre la navigation utilisateur accessible depuis l'avatar du topbar mobile ;
- afficher correctement les icônes dans la bottom-nav mobile.

Sous-tâche 010 demandée par Cyril :
- réduire le footer mobile à `Mentions légales`, `OpenSource` et version ;
- remplacer le lien externe `Signaler un bug` par un signalement applicatif `Un bug ?` ;
- rattacher les bugs à l'organisation courante et permettre le suivi public/admin des corrections.

Parenthèse bugfix demandée par Cyril :
- corriger le 500 sur `GET /org/cpme` causé par `Undefined variable $title` dans `resources/views/layouts/app.blade.php`.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement first mobile topbar/bottom-nav fixes
- [x] run frontend build
- [x] validate mobile UI
- [x] implement subtask 010 footer simplification
- [x] add organization-scoped bug report model/routes/views
- [x] validate subtask 010 with focused feature tests and mobile browser checks
- [x] fix organization homepage undefined title regression
- [ ] collect next page-by-page design instructions

---
# Progress Log


## 2026-06-06 09:34:05 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-214-corrections-design-mobile-page-par-page

Status:
IN_PROGRESS

## 2026-06-06 09:37:22 Europe/Paris

Initial corrections implemented directly by OPENCODE after Cyril requested a new task for page-by-page design bugs.

Changes:
- `resources/views/components/mobile-topbar.blade.php`
  - Replaced the static avatar/profile link with an accessible user dropdown.
  - Added main navigation/account links already present in the desktop menu: dashboard, public profile, create service, create request, points, invitations, favorites, my posts, profile settings, admin when applicable, logout.
  - Changed the messages/notification icon from a non-navigating button to a link to `messages.index`.
  - Replaced unsupported Tailwind icon sizing class `w-4.5 h-4.5` with `w-5 h-5`.
- `resources/views/components/mobile-bottom-nav.blade.php`
  - Replaced unsupported Tailwind icon sizing classes `w-5.5 h-5.5` with `w-6 h-6`.
  - Removed unnecessary Alpine `:class` binding on static Blade-rendered nav links.
  - Added SVG stroke cap/join attributes and `aria-hidden="true"` for decorative icons.

Validation:
- `npm run build` passed.
- Playwright mobile 375x812 on `https://test.laravel/explorer` confirmed:
  - topbar user menu button is visible;
  - menu opens and exposes navigation links;
  - bottom-nav exposes icons and labels for Boucles, Échanges, Objectifs, Actus;
  - console has 0 errors.

Artifacts:
- Playwright screenshot: `task-214-mobile-menu.png`.

Notes:
- Browser warnings remain unrelated to this first correction: multiple Alpine instances and deprecated Apple mobile web app meta warning.
- `public/build` changed after local Vite build and is not intended as part of the source change unless finalization policy requires built assets.

## 2026-06-06 16:05:35 Europe/Paris

Sous-tâche 010 — Footer mobile et signalement de bugs applicatif — implemented by OPENCODE.

Changes:
- `resources/views/partials/footer.blade.php`
  - Replaced the verbose footer with a discreet mobile-first inline footer: `Mentions légales`, `OpenSource`, `Un bug ?`, and `config('app.version')`.
  - Kept the GitHub destination under the `OpenSource` label with a small GitHub icon.
  - Added an Alpine popup for `Un bug ?` with authenticated bug submission and guest login/list links.
  - Preserved organization-prefixed routes when the current page is under `/org/{organization}`.
- `app/Models/BugReport.php`
  - Added a dedicated bug report model separate from content `Report`.
- `database/migrations/2026_06_06_100000_create_bug_reports_table.php`
  - Added `bug_reports` with organization scope, reporter, reason, details, URL, user agent, status, optional resolution notes, and fixed timestamp.
- `app/Http/Controllers/BugReportController.php`
  - Added public organization-scoped bug listing and authenticated bug creation.
- `app/Http/Controllers/Admin/AdminBugReportController.php`
  - Added global admin bug list, mark-as-fixed action with optional public note, and dismiss action.
- `routes/web.php`
  - Added root, organization-prefixed, and admin routes for bug reporting.
- `resources/views/bug-reports/index.blade.php`
  - Added public bug list showing pending/fixed bugs and correction notes while hiding dismissed bugs.
- `resources/views/admin/bug-reports.blade.php`
  - Added admin bug management page under `/admin/bugs-reports`.
- `resources/views/layouts/admin.blade.php`
  - Added `Bugs` entry with pending-count badge.
- `app/Providers/AppServiceProvider.php`
  - Added pending bug count injection for the admin layout.
- `tests/Feature/BugReportTest.php`
  - Added coverage for default organization reporting, organization-prefixed reporting, public fixed-note visibility, dismissed bug hiding, and admin fixed status.

Validation:
- `php artisan migrate --no-interaction` applied the additive `bug_reports` migration locally. No destructive migration command was used.
- `php artisan route:clear --no-interaction` cleared stale cached routes, then `php artisan route:list --path=bugs --no-interaction` showed expected root, org, and admin routes.
- `php -l` passed for the new model/controllers/migration and `routes/web.php`.
- `vendor/bin/pint` passed on the changed PHP perimeter only.
- PostgreSQL test preflight passed before PHPUnit:
  - `database.default = pgsql`
  - `database.connections.pgsql.database = bouclepro_test`
- `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=BugReportTest` passed: 4 tests, 12 assertions.
- `npm run build` passed with Vite.
- `git diff --check` passed.
- Playwright mobile 375x812 confirmed the new footer on `/explorer` with 0 console errors.
- Playwright confirmed `/bugs` and `/org/cpme/bugs` render without console errors and the CPME page resolves the organization-scoped title.

Notes:
- `php artisan test --filter=BugReportTest --no-interaction` was attempted first, but PHPUnit 12 rejected `--no-interaction`; the test was rerun safely without that flag after the database preflight.
- `public/build` artifacts and `synchro_pgsql-avant-migration/HOWTO.md` remain unrelated/unowned dirty worktree changes and must not be staged for this subtask unless Cyril explicitly asks.

## 2026-06-06 16:24:38 Europe/Paris

Parenthèse bugfix — Organization homepage 500 on `/org/cpme` — implemented by OPENCODE.

Issue:
- Cyril reported `GET /org/cpme` failing with `Undefined variable $title` from `resources/views/layouts/app.blade.php:12`.
- Root cause: `organization/landing.blade.php` extends `layouts.app` without passing a `title` variable, while the layout called `filled($title)` directly in the `<title>` tag and mobile topbar prop.

Change:
- `resources/views/layouts/app.blade.php`
  - Guarded title usage with `isset($title) && filled($title)` in the document title and mobile topbar title prop.
  - Preserved the existing behavior for views that do pass a title.

Validation:
- `php -l resources/views/layouts/app.blade.php` passed.
- `git diff --check -- resources/views/layouts/app.blade.php` passed.
- Playwright navigation to `https://test.laravel/org/cpme` no longer returns a 500; guest session redirects to `/org/cpme/login` as expected for a non-public organization, with 0 console errors.
- Safe PHPUnit run on `bouclepro_test` passed:
  - `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter='T1404OrganizationParallelRoutesTest|T1392RouteSmokeGatesTest::test_organization_home_returns_200'`
  - Result: 16 tests, 24 assertions.

# Handoffs

# Tests

- [x] feature tests
- [x] browser validation
- [x] responsive validation
- [x] console inspection
- [x] tenant validation

---

# Test Results

- 2026-06-06 09:36 Europe/Paris — `npm run build` passed with Vite.
- 2026-06-06 09:36 Europe/Paris — Playwright mobile 375x812 on `/explorer` passed for topbar menu opening and bottom-nav icon presence.
- 2026-06-06 09:36 Europe/Paris — browser console inspection: 0 errors, 2 warnings unrelated to these edits.
- 2026-06-06 16:05 Europe/Paris — `php artisan migrate --no-interaction` applied additive `bug_reports` migration locally.
- 2026-06-06 16:05 Europe/Paris — `php artisan route:list --path=bugs --no-interaction` confirmed root, organization, and admin bug routes after route cache clear.
- 2026-06-06 16:05 Europe/Paris — `php -l` passed for new bug report PHP files and `routes/web.php`.
- 2026-06-06 16:05 Europe/Paris — `vendor/bin/pint` passed on changed PHP perimeter.
- 2026-06-06 16:05 Europe/Paris — safe test DB preflight confirmed `pgsql` + `bouclepro_test`.
- 2026-06-06 16:05 Europe/Paris — `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=BugReportTest` passed: 4 tests, 12 assertions.
- 2026-06-06 16:05 Europe/Paris — Playwright mobile footer check passed on `/explorer`, `/bugs`, and `/org/cpme/bugs` with 0 console errors.
- 2026-06-06 16:24 Europe/Paris — `php -l resources/views/layouts/app.blade.php` passed after the `/org/cpme` title guard fix.
- 2026-06-06 16:24 Europe/Paris — Playwright confirmed `https://test.laravel/org/cpme` no longer returns 500; guest redirects to `/org/cpme/login` with 0 console errors.
- 2026-06-06 16:24 Europe/Paris — `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter='T1404OrganizationParallelRoutesTest|T1392RouteSmokeGatesTest::test_organization_home_returns_200'` passed: 16 tests, 24 assertions.

---

# Review Notes

- First slice only. Awaiting Cyril's next page-by-page design instructions before broadening the scope.
- Sous-tâche 010 is implemented and validated, but not committed yet in this update batch.
- Do not merge yet; TASK remains IN_PROGRESS and locked by OPENCODE.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
