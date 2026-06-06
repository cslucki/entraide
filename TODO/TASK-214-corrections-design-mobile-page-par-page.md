---
task_id: TASK-214
title: Corrections design mobile page par page

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-214-corrections-design-mobile-page-par-page

priority: MEDIUM

created_at: 2026-06-06 09:34:05 Europe/Paris
updated_at: 2026-06-06 21:52:44 Europe/Paris

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

Parenthèse repository hygiene demandée par Cyril :
- empêcher `synchro_pgsql-avant-migration/` d'être publié sur GitHub car c'est un outil interne et éphémère.

Sous-tâche 011a/011b demandée par Cyril :
- tester le nouveau logo BouclePro et la police Inter sur la homepage publique uniquement avant déploiement global.
- corriger le prototype pour placer le logo dans le screen header invité, afficher la bottom navigation invitée, et retirer le logo du hero.

Sous-tâche 011c demandée par Cyril :
- déployer le système `logo + organizations.name` sur les écrans desktop ;
- utiliser `config('app.name')` comme fallback sur `/` quand aucune organisation courante n'est résolue ;
- sur smartphone, afficher un titre de fonctionnalité pour les pages niveau 1 ;
- sur smartphone, afficher une top app bar niveau 2 avec bouton retour rond + titre de la page courante ;
- appliquer le même shell mobile header/footer à la page login.

Sous-tâche 012 demandée par Cyril :
- corriger l'instabilité du body desktop entre `Échanges`, `Annuaire` et `Blog` ;
- créer un PageContainer commun pour aligner titres, contenus, filtres et actions ;
- ne pas modifier la logique métier, les routes, les cartes ou le système de navigation.

Sous-tâche 013 demandée par Cyril :
- créer une page 404 customisée noire avec logo BouclePro, texte marrant, moteur de recherche, lien retour accueil
- adapter selon l'organization ID

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
- [x] de-index internal synchro tool directory from Git
- [x] implement subtask 011a/011b homepage-only logo and Inter prototype
- [x] implement subtask 011c desktop brand and mobile top app bar corrections
- [x] implement subtask 012 shared desktop page container for exchanges/members/blog
- [x] apply PageContainer to boucles/index, profile/show, blog/show, mentions-legales
- [x] fix homepage header to show organization.name instead of app.name
- [x] implement subtask 013 custom dark 404 page
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

## 2026-06-06 16:36:46 Europe/Paris

Parenthèse repository hygiene — internal sync tooling — implemented by OPENCODE after Cyril clarified that `/synchro_pgsql-avant-migration` must not be published to GitHub.

Decision:
- The directory was already present in `.gitignore`, but files were already tracked by Git, so `.gitignore` alone could not prevent publication.
- Staged `git rm --cached -r synchro_pgsql-avant-migration` to remove the directory from Git tracking while preserving local files on disk.

Notes:
- This does not purge the directory from historical commits already pushed previously. Full history purging would require a separate, coordinated repository rewrite and is not attempted here.
- `public/build` artifacts and `docs/design/` remain outside this cleanup commit.

## 2026-06-06 17:29:45 Europe/Paris

Sous-tâche 011a/011b — Prototype logo + Inter sur homepage publique — implemented by OPENCODE after Cyril clarified this must stay limited to the public homepage before global rollout.

Changes:
- `public/brand/bouclepro-header-light.png`
  - Copied from `docs/design/bouclepro-logo-pack-inter/brand/` for application use.
- `public/brand/bouclepro-header-dark.png`
  - Copied from `docs/design/bouclepro-logo-pack-inter/brand/` for application use.
- `resources/views/layouts/app.blade.php`
  - Added `@stack('head')` so page-specific prototype assets can be injected without changing global typography yet.
- `resources/views/home.blade.php`
  - Added Inter loading from Google Fonts for this public homepage prototype only.
  - Wrapped the homepage content with `.public-homepage` so Inter applies to the whole homepage content.
  - Replaced the visible text `BouclePro` hero title with a clickable BouclePro wordmark image and retained an `sr-only` `h1` for accessibility.
  - Used `bouclepro-header-dark.png` in the hero because the current hero background is dark regardless of browser theme; the light file uses black text and is reserved for future light-surface placement.

Scope decision:
- `resources/views/organization/landing.blade.php`, `resources/views/layouts/navigation.blade.php`, global PWA icons, favicon, and `site.webmanifest` were intentionally left out of this prototype.
- The organization badge idea is kept for the later rollout/top-app-bar phase, not applied to the root public homepage.

Validation:
- `git diff --check -- resources/views/layouts/app.blade.php resources/views/home.blade.php resources/views/organization/landing.blade.php` passed.
- `npm run build` passed with Vite.
- Playwright mobile 375x812 on `https://test.laravel/` confirmed:
  - the homepage renders without console errors;
  - the prototype wordmark is `/brand/bouclepro-header-dark.png`;
  - the wordmark renders at 48px high on mobile;
  - Inter is applied to `.public-homepage`;
  - screenshot review found no obvious logo/typography rendering issue.

## 2026-06-06 17:51:20 Europe/Paris

Sous-tâche 011 — Correction homepage mobile invitée — implemented by OPENCODE after Cyril clarified the desired placement from screenshots.

Changes:
- `resources/views/components/mobile-topbar.blade.php`
  - Removed the auth-only wrapper so the mobile screen header also renders for guests.
  - On the root homepage, replaced the text app name with the BouclePro wordmark in the top-left screen header.
  - Added guest controls on the right side: dark/light mode toggle and `Connexion` button.
  - Preserved the authenticated messages/avatar dropdown behavior.
- `resources/views/components/mobile-bottom-nav.blade.php`
  - Removed the auth-only wrapper so the bottom navigation renders for guests.
  - Kept the same visible tabs as authenticated mode: `Boucles`, `Échanges`, `Objectifs`, `Actus`.
  - Uses public-safe guest routes where needed (`boucles.index`, `explorer`, `login`, `blog.index`).
- `resources/views/layouts/app.blade.php`
  - Applies mobile bottom safe-area spacing for guests too, because the bottom navigation is now visible in guest mode.
- `resources/views/home.blade.php`
  - Removed the BouclePro wordmark from the hero.
  - Replaced the hero title with visible text `Bienvenue`.

Validation:
- `git diff --check` passed on changed mobile/homepage views.
- `npm run build` passed with Vite.
- Playwright mobile 375x812 on `https://test.laravel/` confirmed:
  - topbar wordmark is visible from `/brand/bouclepro-header-light.png` at 32px high;
  - header contains a theme toggle and `Connexion` button;
  - hero title is `Bienvenue` and contains no BouclePro logo image;
  - bottom navigation labels are `Boucles`, `Échanges`, `Objectifs`, `Actus`;
  - Inter remains applied to `.public-homepage`;
  - console has 0 errors.

## 2026-06-06 18:50:26 Europe/Paris

Sous-tâche 011 — Micro-correction header mobile invité — implemented by OPENCODE after Cyril reported the dark/light toggle icon was not visible and requested a slightly larger logo.

Changes:
- `resources/views/components/mobile-topbar.blade.php`
  - Increased the homepage header wordmark from `h-8` to `h-9`.
  - Replaced Alpine `x-show`/`template`-dependent theme icons with Tailwind `dark:` visibility classes so the switch icon is visible even before/without Alpine expression rendering.

Validation:
- `git diff --check -- resources/views/components/mobile-topbar.blade.php` passed.
- `npm run build` passed with Vite.
- Playwright mobile 375x812 on `https://test.laravel/` confirmed:
  - visible header logo is `/brand/bouclepro-header-dark.png` at 36px high;
  - exactly one theme switch icon is visible at 20px;
  - `Connexion`, hero `Bienvenue`, and guest bottom navigation remain present;
  - console has 0 errors.

## 2026-06-06 19:12:56 Europe/Paris

Sous-tâche 011c — Correction logo desktop et top app bar mobile — implemented by OPENCODE after Cyril switched back to build mode.

Decisions confirmed by Cyril:
- Desktop logo picto uses the 40px variant (`h-10 w-10`) for visual confirmation.
- Mobile level 2 uses the current page title and a round arrow button for navigation back toward the parent feature.
- Root `/` fallback, when no current organization exists, uses the app name from `.env` through `config('app.name')`, not the default organization name.

Changes:
- `app/Providers/AppServiceProvider.php`
  - Kept `brandOrganizationName` as current organization name when an organization is bound.
  - Changed fallback to authenticated user organization name when available, otherwise `config('app.name')`.
- `resources/views/layouts/navigation.blade.php`
  - Replaced the old desktop favicon mark with `/brand/bouclepro-symbol-64.png` at `h-10 w-10`.
  - Displays the resolved organization/app name directly next to the symbol.
  - Links to the current organization homepage when an organization is bound, otherwise to root `/`.
- `resources/views/components/mobile-topbar.blade.php`
  - Replaced homepage-only logo behavior with a route-aware mobile app bar.
  - Level 1 routes display feature titles such as `Accueil`, `Échanges`, `Boucles`, `Actus`, `Mon espace`, and `Connexion`.
  - Level 2 routes display a round `Retour` button plus the current page title.
  - Service/request pages return to `Échanges`, blog pages return to `Actus`, loop pages return to `Boucles`, and account pages return to `Mon espace`.
  - Deduces titles from route models when available: service title, request title, blog post title, user name, loop name.
  - Removes the redundant `Connexion` button when already on login.
- `resources/views/components/mobile-bottom-nav.blade.php`
  - Keeps organization-prefixed navigation inside `/org/{organization}` where parallel organization routes exist.
  - Supports active state for `organization.*` route names.
- `resources/views/layouts/guest.blade.php`
  - Adds the same fixed mobile topbar and bottom navigation as the app layout.
  - Keeps the previous desktop login brand block hidden from mobile to avoid header position jumps.
  - Adds the same mobile safe-area spacing and `@stack('head')` support.

Validation:
- `php -l app/Providers/AppServiceProvider.php` passed.
- `git diff --check` passed.
- `npm run build` passed with Vite.
- Playwright mobile 375x812 on `/login` confirmed:
  - fixed topbar title `Connexion`;
  - visible theme switch;
  - no duplicate `Connexion` button in the topbar;
  - bottom navigation labels `Boucles`, `Échanges`, `Objectifs`, `Actus`;
  - console has 0 errors.
- Playwright mobile 375x812 on a service level-2 page confirmed:
  - round `Retour` button points to `/explorer`;
  - topbar title is the service title `Relecture et correction orthographique de vos exposés ou dissertations.`;
  - bottom navigation remains present;
  - console has 0 errors.
- Playwright desktop 1280x900 on `/explorer` confirmed:
  - desktop nav shows `/brand/bouclepro-symbol-64.png` plus brand name `BouclePro` at left;
  - console has 0 errors.

Notes:
- `public/build` remains generated/dirty from local Vite builds and must not be staged unless explicitly requested.
- `docs/design/` remains source-resource input and must not be staged.

## 2026-06-06 19:23:44 Europe/Paris

Sous-tâche 012 — Alignement commun du body desktop — implemented by OPENCODE after Cyril provided `docs/design/01-alignement_body_contenu.md`.

Audit findings:
- `resources/views/explorer.blade.php` used `max-w-7xl mx-auto px-4 py-4 sm:py-8`.
- `resources/views/members/index.blade.php` used `max-w-6xl mx-auto px-4 py-10`.
- `resources/views/blog/index.blade.php` used `max-w-7xl mx-auto px-4 py-8`.
- These divergent `max-width`, horizontal padding, and vertical rhythm classes caused visible X/Y jumps between desktop pages.

Changes:
- `resources/views/components/page-container.blade.php`
  - Added a shared Blade component with `mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8`.
- `resources/views/explorer.blade.php`
  - Replaced the page wrapper with `<x-page-container>`.
  - Kept the Livewire explorer logic unchanged.
  - Normalized the desktop page title style and spacing.
- `resources/views/members/index.blade.php`
  - Replaced the page wrapper with `<x-page-container>`.
  - Kept member cards, counts, pagination, and links unchanged.
  - Normalized the desktop title size with the shared page rhythm.
- `resources/views/blog/index.blade.php`
  - Replaced the page wrapper with `<x-page-container>`.
  - Kept article cards, sidebar, pagination, categories, tags, and auth action unchanged.

Validation:
- `git diff --check -- resources/views/components/page-container.blade.php resources/views/explorer.blade.php resources/views/members/index.blade.php resources/views/blog/index.blade.php` passed.
- `npm run build` passed with Vite.
- Playwright desktop 1280x900 measured `/explorer`, `/membres`, and `/blog`:
  - all three page titles start at `x=32`;
  - all three page titles start at `y=97`;
  - no horizontal overflow (`scrollWidth === clientWidth`).
- Playwright mobile 375x812 measured `/explorer`, `/membres`, and `/blog`:
  - no horizontal overflow (`scrollWidth === clientWidth`) on all three pages.

Notes:
- A pre-existing 403 console error appears on `/blog` for one stored blog image (`/storage/blog/...png`). It is unrelated to this layout-only change.
- Existing unrelated dirty files remain outside this subtask: generated `public/build` artifacts, 011 homepage/layout leftovers, untracked `docs/design/`, and untracked header wordmark assets.

## 2026-06-06 19:41:44 Europe/Paris

Corrections post-012 — 4 pages non adaptées + header `/` — implémentées par OPENCODE après rapport de Cyril.

Problèmes signalés :
- `/boucles` (boucles.index) — container inline `max-w-5xl`, pas de PageContainer.
- `/profile/{user}` (profile.show) — container inline `max-w-4xl`, pas de PageContainer.
- `/blog/{slug}` (blog.show) — container inline `max-w-7xl`, pas de PageContainer.
- `/mentions-legales` — container inline `max-w-3xl`, pas de PageContainer.
- `/` header — affichait `config('app.name')` au lieu du nom de l'organisation par défaut.

Changements :
- `resources/views/boucles/index.blade.php` — remplace `<div class="max-w-5xl ...">` par `<x-page-container>`.
- `resources/views/profile/show.blade.php` — remplace `<div class="max-w-4xl ...">` par `<x-page-container>`.
- `resources/views/blog/show.blade.php` — remplace `<div class="max-w-7xl ...">` par `<x-page-container>`.
- `resources/views/mentions-legales.blade.php` — remplace `<div class="max-w-3xl ...">` par `<x-page-container>`.
- `app/Providers/AppServiceProvider.php` — dans le fallback sans `current_organization`, utilise `$defaultOrg?->name` avant `config('app.name')` pour `brandOrganizationName`.
- `resources/views/components/mobile-topbar.blade.php` — ajoute `'mentions-legales' => 'Mentions légales'` aux titres niveau 1.

Validation :
- `git diff --stat` confirmé.
- `npm run build` validé.
- Changements prêts à committer.

## 2026-06-06 21:52:44 Europe/Paris

Sous-tâche 013 — Page 404 customisée noire — implémentée par OPENCODE après demande de Cyril.

Décisions de conception confirmées par Cyril :
- Texte : « Hmm, cette boucle semble partie en vacances… »
- Fond noir avec grand logo BouclePro
- Formulaire de recherche en dessous du logo
- Lien retour à l'accueil

Changements :
- `app/Providers/AppServiceProvider.php`
  - Ajoute `resolveOrganizationFromRequest()` : détecte l'org depuis l'URL (`/org/{slug}`) pour les pages d'erreur où la middleware n'a pas pu résoudre l'Organization.
  - Partage `currentOrganization` via View::composer('*') pour que toutes les vues (y compris 404) aient accès à l'Organization.
- `resources/views/errors/404.blade.php`
  - Page standalone (pas de layout) avec fond `bg-gray-950`.
  - Logo BouclePro à 112px (mobile) / 144px (desktop).
  - Titre « Hmm, cette boucle semble partie en vacances… » en gris clair.
  - Barre de recherche pointant vers `/search` avec icône loupe.
  - Lien retour à l'accueil : `/org/{slug}` si org détecté, `/dashboard` si auth, `/` sinon.
  - Support dark mode natif, Tailwind pur.

Validation :
- `php -l app/Providers/AppServiceProvider.php` — passed.
- `npm run build` — passed (Vite).
- Playwright sur `GET /nonexistent-page` : rendu correct de la 404 (logo, titre 404, texte, champ recherche, lien retour).
- Playwright sur `GET /org/main/nonexistent` : rendu correct, lien retour vers `/org/main`.
- Console error = response 404 (attendue), pas d'erreur JS/CSS.

## 2026-06-06 23:07:21 Europe/Paris

Sprint final — 4 corrections design post-013 — implémentées par OPENCODE.

Corrections :
1. **Header login** — le login page affichait `Connexion` comme titre banal, sans logo BouclePro ni nom d'organisation. Le `guest.blade.php` a été modifié pour déplacer l'image + nom dans l'en-tête mobile, et le `mobile-topbar` gère déjà le routing login avec logo + lien "Accueil BouclePro".
2. **Bottom nav visible sur desktop** — déjà corrigé : `mobile-bottom-nav.blade.php` ligne 1 a `md:hidden`.
3. **Bottom nav absente sur /services, /messaging, /profile** — déjà inclus via `app.blade.php` ligne 66 (`<x-mobile-bottom-nav />`).
4. **Favicon 404** — Apache a un `Alias /icons/` builtin qui intercepte `/icons/` avant le DocumentRoot. Solution : fichiers favicon déplacés de `/icons/` vers la racine `public/` (`favicon.ico`, `favicon.svg`, `favicon-16x16.png`, `favicon-32x32.png`, `apple-touch-icon.png`). Icônes PWA déplacées vers `/brand/` (`icon-192.png`, `icon-512.png`, `maskable-192.png`, `maskable-512.png`). `site.webmanifest` mis à jour. Toutes les URLs retournent 200.

Changements :
- `public/favicon.ico` — écrasé par la version BouclePro (6149 bytes)
- `public/favicon.svg` — écrasé par la version BouclePro
- `public/apple-touch-icon.png` — écrasé par la version BouclePro
- `public/favicon-16x16.png` — nouvelle copie depuis le logo pack
- `public/favicon-32x32.png` — nouvelle copie depuis le logo pack
- `public/brand/favicon.ico` — copie pour backup
- `public/brand/favicon.svg` — copie pour backup
- `public/brand/apple-touch-icon.png` — copie pour backup
- `public/brand/icon-192.png` — nouvelle copie (PWA)
- `public/brand/icon-512.png` — nouvelle copie (PWA)
- `public/brand/maskable-192.png` — nouvelle copie (PWA)
- `public/brand/maskable-512.png` — nouvelle copie (PWA)
- `public/site.webmanifest` — chemins mis à jour de `/icons/` vers `/brand/`
- `resources/views/layouts/app.blade.php` — chemins favicon mis à jour de `/icons/` vers `/`
- `resources/views/layouts/guest.blade.php` — chemins favicon mis à jour de `/icons/` vers `/`

Validation :
- `curl` vérifié : toutes les URLs favicon retournent 200.
- `npx playwright test login-member.spec.js` — 4/4 passed (chromium, webkit, firefox, mobile-chrome) en 7.5s.
- Console 0 erreurs favicon 404.

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
- 2026-06-06 16:36 Europe/Paris — `git rm --cached -r synchro_pgsql-avant-migration` staged removal from Git tracking while keeping local files available because the directory is ignored.
- 2026-06-06 17:29 Europe/Paris — `npm run build` passed after the homepage logo/Inter prototype.
- 2026-06-06 17:29 Europe/Paris — Playwright mobile 375x812 on `/` confirmed wordmark `/brand/bouclepro-header-dark.png`, 48px logo height, Inter font family on `.public-homepage`, and 0 console errors.
- 2026-06-06 17:51 Europe/Paris — `npm run build` passed after moving the logo to the guest mobile screen header and enabling guest bottom navigation.
- 2026-06-06 17:51 Europe/Paris — Playwright mobile 375x812 on `/` confirmed screen-header logo, theme toggle, `Connexion`, hero `Bienvenue`, guest bottom navigation, Inter, and 0 console errors.
- 2026-06-06 18:50 Europe/Paris — `npm run build` passed after increasing the mobile header logo and making the theme icon visible via Tailwind `dark:` classes.
- 2026-06-06 18:50 Europe/Paris — Playwright mobile 375x812 on `/` confirmed one visible switch icon, 36px header wordmark, `Connexion`, `Bienvenue`, guest bottom navigation, and 0 console errors.
- 2026-06-06 19:12 Europe/Paris — `php -l app/Providers/AppServiceProvider.php`, `git diff --check`, and `npm run build` passed after 011c.
- 2026-06-06 19:12 Europe/Paris — Playwright mobile checks passed for `/login` and a service level-2 page; desktop `/explorer` confirmed 40px symbol + brand name in navigation; console had 0 errors.
- 2026-06-06 19:23 Europe/Paris — `git diff --check` and `npm run build` passed after adding `x-page-container` to `/explorer`, `/membres`, and `/blog`.
- 2026-06-06 19:23 Europe/Paris — Playwright desktop 1280x900 confirmed title alignment at `x=32`, `y=97` for `/explorer`, `/membres`, `/blog`, with no horizontal overflow.
- 2026-06-06 19:23 Europe/Paris — Playwright mobile 375x812 confirmed no horizontal overflow on `/explorer`, `/membres`, `/blog`.

## 2026-06-06 23:30:11 Europe/Paris

Sous-tâche 015 — Couleur hero configurable par organisation — implémentée par OPENCODE après demande de Cyril.

Problème :
- Le hero background utilisait un dégradé hardcodé `from-indigo-600 to-purple-700` (homepage racine) ou un simple fade `accent_color → accent_color dd` (landing org).
- Le champ `accent_color` dans l'admin ne servait pas au hero gradient.
- Aucune colonne gradient dans la BDD ni champ dans le formulaire admin.

Décisions de conception confirmées par Cyril :
- Scope : chaque org + homepage racine.
- Auto-déduction : l'application calcule la couleur de fin du dégradé automatiquement (assombrissement HSL 25%).
- Palette de confiance : 11 couleurs swatches fournies par Cyril.

Changements :
- `database/migrations/2026_06_06_200414_add_hero_gradient_start_to_organizations_table.php`
  - Nouvelle colonne `hero_gradient_start VARCHAR(7) NULL` après `hero_description`.
- `app/Support/ColorHelper.php`
  - Nouveau helper statique : `darken(hex, percent)` → convertit hex → HSL → réduit la luminosité → retourne hex assombri.
- `app/Models/Organization.php`
  - Ajoute `hero_gradient_start` à `$fillable`.
  - Ajoute accesseur `heroGradientEnd` : priorité `hero_gradient_start` → `accent_color` → fallback `#4f46e5` → darken 25%.
- `app/Http/Controllers/HomeController.php`
  - Passe `$defaultOrganization` (première org is_default) à la vue home.
- `app/Http/Controllers/Admin/AdminOrganizationController.php`
  - Ajoute `hero_gradient_start` à la validation update (string, regex hex).
- `resources/views/admin/organizations/edit.blade.php`
  - Nouveau champ "Couleur du dégradé hero" avec 11 swatches cliquables + picker color + input hex.
  - Sync JS bidirectionnelle picker ↔ input.
- `resources/views/organization/landing.blade.php`
  - Gradient inline style : utilise `hero_gradient_start ?? accent_color` + `hero_gradient_end`.
- `resources/views/home.blade.php`
  - Hero remplace la classe Tailwind hardcodée par inline `linear-gradient(135deg, start, end)`.
  - Utilise les couleurs de l'org par défaut avec fallback indigo.

Validation :
- `php -l` passed sur tous les fichiers modifiés.
- Migration additive `2026_06_06_200414` exécutée sur `bouclepro`.
- `curl https://test.laravel/` : gradient `linear-gradient(135deg, #1237C9 0%, #081754 100%)` présent.
- `curl https://test.laravel/org/main` : 302 redirect (org non publique) — pas d'erreur 500.
- `npx playwright test login-member.spec.js --project=webkit` : 1 passed (4.2s).

## 2026-06-06 23:45:11 Europe/Paris

Correction mode light sur la page de connexion — implémentée par OPENCODE après rapport de Cyril.

Problème :
- `<html class="dark">` forcé sur la guest layout → le JS de préférence utilisateur pouvait le retirer, mais le body restait `bg-gray-900` (toujours sombre).
- En mode light : le JS enlève `dark` → `text-gray-900` (noir) sur `bg-gray-900` (sombre) → texte illisible.
- L'org name desktop s'affichait en noir sur fond sombre.

Changements :
- `resources/views/layouts/guest.blade.php`
  - Retire `class="dark"` forcé sur `<html>` — le JS gère dynamiquement.
  - Body passe de `bg-gray-900` (fixe sombre) à `bg-gray-50 dark:bg-gray-900`.

Validation :
- `curl https://test.laravel/login` : `<html lang="fr">` (pas de dark forcé), `body class="... bg-gray-50 dark:bg-gray-900"`.
- `npx playwright test login-member.spec.js` : 4/4 passed (8.8s).

## 2026-06-06 23:55:11 Europe/Paris

Nettoyage repository — déplacement design/ + ajout PWA icons — effectué par OPENCODE.

Actions :
- `docs/design/` → `./design/` (déplacé à la racine du projet)
- `public/icons/` supprimé (répertoire abandonné après conflit Apache)
- `task-214-404-page.png` supprimé (screenshot Playwright)
- `.gitignore` : ajout de `/design/`
- `public/brand/icon-192.png`, `icon-512.png`, `maskable-192.png`, `maskable-512.png` commités (PWA icons référencées par `site.webmanifest`)

Fichiers untrackés restants (backups inutilisés, build artifacts) :
- `public/brand/apple-touch-icon.png`, `favicon*` (backups)
- `public/build/` (généré par Vite)

---

# Review Notes

- First slice, subtask 010, and repository hygiene commits are already present on the task branch.
- Sous-tâche 011a/011b prototype has been extended by 011c to desktop navigation, mobile route-aware top app bar, and login mobile shell. PWA icons/favicon/site manifest are still not migrated globally.
- Sous-tâche 012 is layout-only: shared container applied to Échanges, Annuaire, and Blog without changing route/controller/model/business logic or card internals.
- Do not merge yet; TASK remains IN_PROGRESS and locked by OPENCODE.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
