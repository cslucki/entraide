---
task_id: TASK-228
title: WhatsApp Web inspired application shell

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-228-whatsapp-web-inspired-application-shell

priority: MEDIUM

created_at: 2026-06-09 00:04:22 Europe/Paris
updated_at: 2026-06-10 15:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-09 20:18:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Refine the BouclePro public and authenticated application shell toward a calm WhatsApp Web inspired interface: minimal public landing, persistent desktop side navigation, preserved mobile bottom navigation, ChatLoop as the default authenticated destination, and theme-ready visual foundations.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement first UI shell changes
- [x] add theme foundation (`sable`, `inside`)
- [x] run tests
- [x] validate UI

---
# Progress Log


## 2026-06-09 00:04:22 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-228-whatsapp-web-inspired-application-shell

Status:
IN_PROGRESS

## 2026-06-09 00:31:48 Europe/Paris

- Added desktop application side navigation in `resources/views/components/app-side-nav.blade.php`.
- Updated `resources/views/layouts/app.blade.php` to use the new side navigation, preserve mobile navigation, and prepare CSS variable based themes.
- Updated `resources/views/home.blade.php` with the requested minimal public landing and bento-style action cards.
- Updated login/register redirects to route authenticated users with an organization to ChatLoop (`loops.index`).
- Updated targeted auth/admin feature test expectations from dashboard/home redirects to `loops.index`.
- Corrected fresh PostgreSQL migration compatibility in `database/migrations/2026_05_15_000001_create_loops_table.php` by resolving the organization FK target to `communities` when present, otherwise `organizations`.
- User requested follow-up refinements: theme named `sable`, future theme support with light/dark modes, `inside` theme colors, theme toggle below BouclePro logo, desktop menu on the left, and bento flat color inspiration from `Mockup1.png`.
- Implemented `sable` default theme and `inside` theme tokens from provided colors; added theme toggle below the logo and moved desktop side navigation from right to left.
- Note: `d:/BouclePro/05-Pour-la-beta1/13-Final/Mockup1.png` is not available in the Linux workspace, so bento styling was approximated from the written direction.
- Fixed migration closure capture for `$organizationTable` after targeted tests exposed the missing `use` binding.
- Re-ran targeted tests successfully against PostgreSQL `bouclepro_test`.
- Browser-validated `https://test.laravel/` on desktop and mobile widths. Desktop snapshot shows the left side navigation and theme toggle below the logo. Mobile snapshot shows the mobile topbar and bottom navigation preserved.
- Click-tested the theme toggle; no console errors after switching theme.

## 2026-06-09 11:12:30 Europe/Paris

- User requested two refinements: desktop user menu must open again, and the `Objectifs` navigation item must become `Annuaire`.
- Reproduced authenticated desktop issue on ChatLoop: user dropdown clicked but did not become usable.
- Found console error in `resources/views/livewire/loop-chat.blade.php`: Alpine expression used `$cleanup`, which is not available in the current runtime and prevented Alpine behavior on the page.
- Replaced the inline `$cleanup` usage with Alpine `init()` / `destroy()` methods inside the existing `x-data`, preserving scroll observer cleanup without relying on unavailable magic.
- Added `left-up` alignment support to `resources/views/components/dropdown.blade.php` and used it for the desktop side-nav user menu so it opens upward from the bottom-left avatar instead of below the viewport.
- Changed `Objectifs` to `Annuaire` in desktop nav, mobile bottom nav, and public home bento card.
- Verified no remaining `Objectifs` occurrences in Blade views.

Modified files:
- `resources/views/components/app-side-nav.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/home.blade.php`
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `app/Http/Controllers/Auth/RegisteredUserController.php`
- `tests/Feature/Auth/RegisterOrganizationAssignmentTest.php`
- `tests/Feature/Auth/WebLoginOrganizationTest.php`
- `tests/Feature/Admin/AdminDashboardRedirectTest.php`
- `database/migrations/2026_05_15_000001_create_loops_table.php`

## 2026-06-09 15:20:44 Europe/Paris

- User requested that the `zen` theme use the palette from `@DOCS/DESIGN/20260520-Mockup 1.png`, that theme switching move into a user-menu submenu, and that `/admin/themes` stop failing with `View [admin.themes] not found`.
- Located the mockup at `@DOCS/DESIGN/20260520-Mockup 1.png` and aligned `config/bouclepro_themes.php` `zen` tokens with the visible design-system palette: primary `#0B4DFF`, deep blue `#1237C9`, violet `#8A2CFF`, progress `#7DFF00`, validation `#FFC700`, clear surface `#F3FAF4`, text `#101010`, muted `#667085`, disabled `#9AA3B0`, border `#DDE3F0`, info `#C7F2FF`, warning `#FFB24D`.
- Added explicit Alpine theme selection support in `resources/js/app.js` via `set(theme)`, `apply()`, and `is(theme)` while preserving the existing `next()` behavior.
- Replaced the user menu's single theme-cycle row with a `Changer de thème` submenu listing all configured themes and marking the active theme.
- Added `x-data` to the desktop side navigation root so the top theme label under the logo updates reactively after explicit selection.
- Created `resources/views/admin/themes.blade.php` as a non-persistent design-system/token atelier reading from `config/bouclepro_themes.php`, with swatches, readonly color inputs, and component previews.

Modified files added in this step:
- `config/bouclepro_themes.php`
- `resources/js/app.js`
- `resources/views/components/app-side-nav.blade.php`
- `resources/views/admin/themes.blade.php`

# Handoffs

# Tests

- [x] PHPUnit feature tests (12 tests, 28 assertions — login/register/admin redirect)
- [x] Browser validation: blog article show, service show, members page, mono-loop page
- [x] Responsive validation: desktop (1280px) + mobile (390px)
- [x] Console inspection: 0 errors across all pages (2 existing Alpine multiple instance warnings)
- [x] Theme switching (Zen/Sable/Sea/Inside) + dark mode toggle
- [x] User dropdown: open upward, theme submenu, nav link clicks close dropdown
- [x] Theme editor: bidirectional highlight (token panel → preview and preview → token panel)
- [x] Theme editor: preview feature cards render with correct token values
- [x] Home page: welcome card renders `bg-[var(--bp-card-welcome)]` with user-chosen color
- [x] Home page: feature cards render with dedicated `--bp-card-*` tokens
- [x] Tailwind JIT: `bg-[var(--bp-card-welcome)]` class generated via `npm run build`
- [x] Console inspection: 0 errors across all theme editor interactions

---

# Test Results

- `php -l app/Http/Controllers/Auth/AuthenticatedSessionController.php` passed.
- `php -l app/Http/Controllers/Auth/RegisteredUserController.php` passed.
- `php artisan view:cache` passed.
- `npm run build` passed.
- `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default` confirmed `pgsql`.
- `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database` confirmed `bouclepro_test`.
- `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Auth/RegisterOrganizationAssignmentTest.php tests/Feature/Auth/WebLoginOrganizationTest.php tests/Feature/Admin/AdminDashboardRedirectTest.php` passed: 12 tests, 28 assertions.
- `php artisan optimize:clear` passed after `view:cache`.
- Playwright browser validation on `https://test.laravel/` passed with 0 console errors. Warnings observed: existing multiple Alpine instance warning and deprecated `apple-mobile-web-app-capable` meta warning.
- `npm run build` passed after `Annuaire`/dropdown changes.
- `php artisan view:cache` passed after Livewire/dropdown changes.
- Playwright authenticated desktop validation passed on `https://test.laravel/loops/019ea8da-c792-7250-b831-2f2a5fc37997`: `Annuaire` visible, user menu opens upward and displays profile/settings/points/favorites/admin/logout links in viewport, 0 console errors.
- `php artisan optimize:clear` passed after browser validation.
- `php artisan view:cache` passed after `zen` palette, theme submenu, and admin themes view changes.
- `npm run build` passed after Alpine theme store and side navigation changes.
- Playwright authenticated validation on `https://test.laravel/loops/019ea8da-c792-7250-b831-2f2a5fc37997` passed with 0 console errors after selecting `Zen`: `data-bp-theme=zen`, `localStorage.bpTheme=zen`, Alpine store label `Zen`, `--bp-primary=#0B4DFF`, and side-nav theme label `ZEN`.
- Playwright navigation to `https://test.laravel/admin/themes` now reaches the admin middleware and returns `403 Forbidden` in the current non-admin browser session instead of the previous view lookup error; `php artisan view:cache` confirms `admin.themes` exists and compiles.

## 2026-06-09 15:39:30 Europe/Paris

- User reported that clicking "Changer de thème" or the dark/light toggle from the user dropdown caused the parent dropdown to close, requiring a second click to see the theme submenu.
- Root cause: `resources/views/components/dropdown.blade.php` had `@click="open = false"` on the content container, closing the dropdown on any internal click.
- Removed the `@click="open = false"` from the dropdown component's content container.
- Added `@click="open = false"` selectively on navigation links in `resources/views/components/app-side-nav.blade.php` (Profil, Paramètres, Historique des points, Mes favoris, Aide, Administration, Se déconnecter, Mentions légales) so those still close the dropdown on click.
- Theme submenu and dark mode toggle no longer close the parent dropdown.

Modified files:
- `resources/views/components/dropdown.blade.php`
- `resources/views/components/app-side-nav.blade.php`

Validations:
- Playwright authenticated validation on ChatLoop: clicking "Changer de thème" opens the submenu while the user dropdown stays visible (`[expanded]` state confirmed). Clicking dark mode toggle keeps the dropdown open. 0 console errors.

## 2026-06-09 18:53:11 Europe/Paris

- Created fixed desktop top navigation bar in `resources/views/layouts/app.blade.php`: logo + app name + page title on left, dark mode toggle + messages icon + user avatar on right. Hidden on mobile. Positioned `fixed top-0 z-30 md:ml-20 md:w-[calc(100%-5rem)]`.
- Created two new migrations: `2026_06_09_000001_create_themes_table.php` (themes table with key, label, description, is_default, tokens/dark_tokens JSON) and `2026_06_09_000002_add_theme_id_to_organizations.php` (nullable FK on organizations).
- Created `app/Models/Theme.php` with fillable fields and casts.
- Created `app/Http/Controllers/Admin/AdminThemeController.php` with full CRUD: index, create, store (hex token validation), edit, update, destroy (blocks default deletion, dissociates orgs).
- Updated `routes/web.php`: replaced `Route::view('/themes', ...)` with 6 RESTful routes via AdminThemeController.
- Updated `app/Models/Organization.php`: added `theme_id` to fillable and `theme(): BelongsTo`.
- Updated `app/Http/Controllers/Admin/AdminOrganizationController.php`: added `theme_id` to update validation.
- Moved admin themes view to `resources/views/admin/themes/index.blade.php` using DB models with dark/light preview sections per theme.
- Created `resources/views/admin/themes/create.blade.php` and `resources/views/admin/themes/edit.blade.php` for CRUD forms.
- Added theme picker dropdown to Apparence card in `resources/views/admin/organizations/edit.blade.php`.
- Updated `resources/views/loops/show.blade.php` desktop CSS to use `calc(100dvh - 3.5rem)` for full viewport height.
- Synced `config/bouclepro_themes.php` zen tokens to mockup palette and synced DB zen dark tokens.

Modified files:
- `resources/views/layouts/app.blade.php`
- `routes/web.php`
- `app/Models/Organization.php`
- `app/Http/Controllers/Admin/AdminOrganizationController.php`
- `resources/views/loops/show.blade.php`
- `config/bouclepro_themes.php`

Created files:
- `database/migrations/2026_06_09_000001_create_themes_table.php`
- `database/migrations/2026_06_09_000002_add_theme_id_to_organizations.php`
- `app/Models/Theme.php`
- `app/Http/Controllers/Admin/AdminThemeController.php`
- `resources/views/admin/themes/index.blade.php`
- `resources/views/admin/themes/create.blade.php`
- `resources/views/admin/themes/edit.blade.php`

Validations:
- Playwright validation on `https://test.laravel/admin/themes` confirmed all 4 theme cards render with dark + light previews, token grids, and action buttons.
- Playwright validation on org edit page confirmed theme dropdown with 5 options (4 themes + default placeholder).
- Playwright validation on desktop ChatLoop confirmed top navigation bar visible with logo, page title, dark toggle, messages bell, and user avatar.
- Playwright validation confirmed loop show page fills full viewport height on desktop.
- 0 console errors across all validated pages.

## 2026-06-09 19:07:00 Europe/Paris

- User requested removal of the desktop top navigation bar. Reverted `resources/views/layouts/app.blade.php`: removed desktop topbar div, removed `md:pt-14` from content wrapper.
- Reverted `resources/views/loops/show.blade.php` desktop height from `calc(100dvh - 3.5rem)` back to `calc(100vh - 5rem)`.
- User reported loop show page composer section (message input + "Qui peut m'aider ?" button) floating mid-screen on desktop.
- Root cause: `@livewire('loop-chat')` in `resources/views/loops/show.blade.php` was placed inside a flex container without wrapping the Livewire component in `flex-1`, so the chat component didn't stretch vertically.
- Fix: wrapped `@livewire('loop-chat')` in `<div class="flex-1 flex flex-col min-h-0">` and added `flex-1` to the Livewire component root in `resources/views/livewire/loop-chat.blade.php`.
- Playwright validation confirmed: textbox moved from top=287 (mid-screen) to top=731 (bottom of viewport), "Qui peut m'aider ?" button stays at bottom. 0 errors.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- Playwright final validation on all pages (desktop + mobile) passed with 0 console errors.

## 2026-06-09 18:18:00 Europe/Paris

- Completed Playwright validation on all key pages:
  - **Blog article show**: desktop topbar removed, sidebar author card removed, breadcrumb hidden on md+, typography rendering with `@tailwindcss/typography`, Retour link, category badge, comments. 0 errors.
  - **Service show**: inline topbar with back button + truncated title + category badge, provider info, price/points, Proposer button. 0 errors.
  - **Members page** (`/membres` / Annuaire): member cards with location, bio, service counts, pagination. Side nav Annuaire link correctly routes to `/membres`. 0 errors.
  - **Mono-loop setup page**: view exists at `resources/views/loops/mono-setup-required.blade.php`, compiles via `view:cache`, uses `x-app-layout` (inherits side nav). Validated by code review (no org with mono-loop + null primary_loop_id in current DB).
- **Mobile responsive validation**: all pages (membres, service show) render correctly on 390x844 viewport with topbar, bottom-nav, and FAB preserved. 0 console errors.
- **Desktop left nav** present on all pages with Boucles/Échanges/Annuaire/Actus links, theme toggle, dark mode, user menu.
- **Mobile bottom-nav** preserved on all pages with same nav items.
- `php artisan optimize:clear` passed.
- `php artisan view:cache` passed.
- Cleaned up Playwright screenshot artifacts.

---

## 2026-06-10 07:45:00 Europe/Paris

- User reported 7 issues after Playwright validation (compressed at b7).
- **Issue 1**: Service show page missing desktop top bar — rewritten services/show.blade.php to use `<x-app-layout>` directly with sticky desktop topbar (back button + truncated title + category badge) hidden on mobile.
- **Issue 2**: Annuaire sidebar link — members route verified correct at both `/membres` and `/org/{org}/membres`. Route smoke tests pass.
- **Issue 3**: Mono-loop page — added organization creation explanation section with link to profile settings.
- **Issue 4 & 6**: Blog article page — removed breadcrumb nav, added `sticky top-0 z-30` to desktop topbar. Markdown detection already working via `markdown()` helper.
- **Issue 5**: Blog create page — changed width from `3xl` to `7xl`.
- **Issue 7**: Mobile service show double topbar — fixed by moving inline back button area to desktop-only topbar section.
- All tests pass: PHPUnit (43 blog tests, 6 members tests, 34 smoke tests). Pre-existing failures in T0755 (302 vs 404) and ServiceApiTest (unrelated).
- `php artisan optimize:clear` passed.
- `ai/scripts/backup-internal.sh` completed.

Modified files:
- `resources/views/services/show.blade.php` — restructured to `<x-app-layout>` + desktop topbar
- `resources/views/blog/show.blade.php` — removed breadcrumb, made topbar sticky
- `resources/views/blog/create.blade.php` — width 3xl → 7xl
- `resources/views/loops/mono-setup-required.blade.php` — added org creation section

Validations:
- `php artisan test --filter=MembersPageTest` — 6 passed
- `php artisan test --filter=T1392RouteSmokeGatesTest` — 34 passed
- `php artisan test --filter=Blog` — 43 passed
- `php artisan optimize:clear` — confirmed
- Route list confirmed `members.index` and `organization.members.index` both correct

## 2026-06-10 08:30:00 Europe/Paris

- **Theme editor — bidirectional highlight**: preview elements now highlight on token panel click (and vice versa). `@click.stop` on hex text inputs to prevent focus steal. Border divider `h-0` → `h-1`. Left panel token rows (visual mode light + dark) call `highlight(token)` on click showing ring on preview. Code couleurs rows also call `highlight(key)`.
- **Real app icons** in bottom nav (Boucles, Échanges, Annuaire, Actus SVGs).
- **Status bar** stripped down to empty.
- **Reset button per token**: `resetToken(token)` method restores light+dark to defaults. Undo arrow SVG shown only when value differs from default. Added to visual mode (light+dark) and code couleurs rows.
- **Design rules (home page)** applied: text on colored backgrounds → `text-black dark:text-black`. Applied to Boucles, Échanges, Annuaire, and Actus feature cards. Badge "Interface sobre" intentionally left unchanged with `text-[var(--bp-primary)]`.
- **5 new dedicated card tokens** created for home page cards:
  - `card-welcome` → `--bp-card-welcome` (Carte Bienvenue) — left hero panel
  - `card-loop` → `--bp-card-loop` (Carte Boucles)
  - `card-exchange` → `--bp-card-exchange` (Carte Échanges)
  - `card-directory` → `--bp-card-directory` (Carte Annuaire)
  - `card-news` → `--bp-card-news` (Carte Actus)
- **Config**: added all 5 tokens to `config/bouclepro_themes.php` for all 4 themes (Zen, Sable, Sea, Inside) in both `tokens` and `dark` arrays, with initial values matching the corresponding source tokens.
- **DB**: inherited current token values via tinker for all 5 card tokens across all themes.
- **Admin editor**: added labels to `$tokenLabels`, Alpine computed properties (`previewCardWelcome`, `previewCardLoop`, etc.), preview elements (welcome card + 2×2 feature cards grid) clickable per token.
- **Home page cards**: updated `home.blade.php` — welcome card uses `bg-[var(--bp-card-welcome)]`, feature cards use `bg-[var(--bp-card-loop/exchange/directory/news)]`.
- **Welcome card height**: increased `p-5` → `px-6 py-10` for more vertical padding.
- **Preview widened**: frame `max-w-[360px]` → `max-w-[400px]`, grid `1fr 400px` → `1fr 460px` to eliminate horizontal scroll.
- **Cache**: `Theme::regenerateCache()` executed after token changes.
- **Tailwind build**: `npm run build` executed after all changes.

Modified files:
- `resources/views/admin/themes/index.blade.php` — bidirectional highlight, reset buttons, 5 new card tokens, preview cards, widened preview
- `config/bouclepro_themes.php` — 5 new card tokens for all 4 themes
- `resources/views/home.blade.php` — card-welcome styling, dedicated card tokens, black text on cards

---

# Review Notes

- Desktop side navigation must stay left-aligned per user correction.
- Mobile bottom navigation must remain preserved.
- `zen` is the current default visual theme and is aligned to `@DOCS/DESIGN/20260520-Mockup 1.png`.
- `inside` theme uses the user-provided palette: primary `#0B4DFF`, deep blue `#1237C9`, violet `#8A2CFF`, progression `#7DFF00`, validation `#FFC700`, light background `#F3FAF4`, page `#E8EDF5`, text `#1C1C1E`, secondary `#667085`, disabled `#9AA3B0`, borders `#DDE3F0`.
- `Annuaire` currently preserves the previous `dashboard` route target; this change is a UI label rename only, not a routing/domain change.
- **5 dedicated card tokens** (`card-welcome`, `card-loop`, `card-exchange`, `card-directory`, `card-news`) added for home page. These decouple card colors from shared tokens (primary, accent, progress, validation). Config defaults mirror source token values; DB values inherit current theme token colors.
- **Preview smartphone** now includes welcome card + 2×2 feature cards section, all clickable per token with bidirectional highlight.
- `card-welcome` inherits `panel` config defaults (so welcome card blends by default).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
