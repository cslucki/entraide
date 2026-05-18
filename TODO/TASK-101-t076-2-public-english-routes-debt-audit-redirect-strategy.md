---
task_id: TASK-101
title: t076-2-public-english-routes-debt-audit-redirect-strategy

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-101-t076-2-public-english-routes-debt-audit-redirect-strategy

priority: MEDIUM

created_at: 2026-05-18 11:37:00 Europe/Paris
updated_at: 2026-05-18 11:50:36 Europe/Paris

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

Produce a documentation-only audit of remaining English public route debt after T076.1 and define a short redirect/removal strategy for a later runtime task.

Scope constraints:

- audit/documentation only
- do not modify `routes/web.php`
- do not modify `app/`, `resources/`, `database/`, or `config/`
- do not run migrations
- do not refactor runtime code
- preserve product terminology: Organization / Loop / Member / Partner
- keep `/boucles` as the canonical French public route for the real Loop concept
- do not keep `/loops` or `/partners` as public target URLs

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted route files
- [x] document English public route debt
- [x] propose redirect/removal strategy
- [x] document test status
- [x] document review outcome

---

# Audit Inputs

Files inspected:

- `routes/web.php`
- `routes/api.php`
- `routes/auth.php`
- `routes/channels.php`
- `routes/console.php`
- `TODO/TASK-100-t076-1-public-french-partners-routes-runtime-minimal.md`

No runtime file was modified during this audit.

---

# Public English Route Debt Audit

## Canonical French Public Routes Already Present

- `GET /partenaires` exists and is the French Partner public index.
- `GET /partenaires/demande` and `POST /partenaires/demande` exist for Partner request intake.
- `GET /boucles` exists and remains the canonical French public route reserved for the real Loop concept.
- `GET /boucles/creer` explicitly redirects to `/partenaires/demande` from T076.1.

## Routes Publiques Anglaises Visibles Utilisateur

| Route actuelle | Cible française proposée | Traitement recommandé | Risque produit | Risque SEO | Risque tenant/Organization | Dépendances |
| --- | --- | --- | --- | --- | --- | --- |
| `GET /search` | `/recherche` | Redirect 301 after creating/confirming `/recherche`; temporary redirect acceptable during rollout if analytics are unknown | Low: search intent is clear | Medium: indexed search URLs may exist | Low on root; medium for tenant-prefixed parity if later added | Add French route alias or route rename in future runtime patch; update sitemap if listed |
| `GET /requests/{request}` | `/demandes/{request}` | Redirect 301 only after confirming model binding and authorization parity | Medium: public request/detail URL may be shared | Medium: detail pages can have backlinks | Medium: must preserve Organization isolation and not resolve a request outside the current tenant boundary | Requires tenant-safe binding review and tests |
| `GET /profile/{user}` | `/membres/{user}` or `/profil/{user}` | Audit future; do not redirect blindly until product wording is decided | Medium: Member profile wording impacts navigation | Medium: profile URLs may be indexed | Medium: Member visibility must remain Organization-scoped where applicable | Product decision: public Member profile URL should align with `/membres` listing or a dedicated `/profil` concept |
| `GET /blog/tag/{slug}` | `/blog/mot-cle/{slug}` | Audit future; optional 301 if taxonomy pages are indexed | Low: `tag` is understandable but not French | Low to medium depending indexation | Low | Requires blog SEO decision and canonical tags review |
| `GET /{tenant}/services/{service}` legacy tenant-prefixed public detail | Future French tenant-prefixed equivalent, likely `/{organization}/services/{service}` if `services` remains acceptable in French | Conservation temporaire / audit futur | Medium: `services` may be acceptable French vocabulary, but route is still part of public URL surface | Medium | High: public is not global; redirect must preserve resolved Organization context | Organization route migration strategy must precede broad redirect |
| `GET /{tenant}/requests/{request}` legacy tenant-prefixed public detail | Future French tenant-prefixed equivalent, likely `/{organization}/demandes/{request}` | Audit futur, no immediate redirect | Medium | Medium | High: request detail must remain scoped to the resolved Organization | Requires Organization-native route prefix strategy and tenant-safe tests |
| `GET /{tenant}/profile/{user}` legacy tenant-prefixed public detail | Future French tenant-prefixed equivalent, likely `/{organization}/membres/{user}` or `/{organization}/profil/{user}` | Audit futur, no immediate redirect | Medium | Medium | High: Member visibility must remain scoped to the resolved Organization | Requires product URL decision plus Organization-native prefix strategy |

Notes:

- `GET /explorer` and `GET /{tenant}/explorer` were inspected. `explorer` is also a French verb/product label, so it is not classified as English debt for T076.2. Keep for future naming review only if product requests it.
- `GET /services/{service}` was inspected. `services` is valid French vocabulary and should not be redirected solely as English debt. Its tenant safety still belongs to a future Organization-scoped public URL audit.
- `GET /sitemap.xml` is a technical standard URL and should be conserved.

## `/partners` Status

- No explicit `/partners` route exists in `routes/web.php` after T076.1.
- Because the dynamic tenant legacy slug constraint does not reserve `partners`, a request to `/partners` can be interpreted as a tenant slug and redirected toward `/partners/` before tenant middleware handles it.
- Recommendation for runtime patch: add an explicit public redirect before the dynamic tenant route: `/partners` -> `/partenaires` with 301 if no analytics risk is identified, and reserve `partners` in the tenant slug negative lookahead at the same time.
- Product risk: medium, because `/partners` currently has no intended public product meaning.
- SEO risk: medium, because old or guessed backlinks should consolidate to `/partenaires`.
- Tenant/Organization risk: high if left to dynamic tenant resolution, because an English marketing URL can be confused with an Organization slug.

## `/loops` Status

- `GET /loops` exists only inside authenticated application route groups on root and tenant-prefixed surfaces.
- It is not a public marketing page equivalent to `/boucles`.
- Do not blindly redirect authenticated `/loops` to `/boucles` in a public redirect patch, because that could break internal Loop CRUD/navigation.
- Recommendation: T076.3 should ensure no unauthenticated public `/loops` target remains. A separate authenticated app URL migration can later decide whether internal Loop routes should become French.
- Product risk: high if `/loops` is treated as equivalent to `/boucles`; `/boucles` is the public French conceptual page, while authenticated `/loops` is current app runtime.
- SEO risk: low to medium, because auth middleware limits public page content but the URL can still be discovered.
- Tenant/Organization risk: medium to high for tenant-prefixed authenticated routes; any future rename must preserve Organization scope and Loop membership checks.

## Routes Internes/Admin/API Non Concernées

These routes contain English path segments but are not public marketing targets for T076.2:

- Auth routes from `routes/auth.php`: `login`, `register`, `forgot-password`, `reset-password`, `verify-email`, `confirm-password`, `logout`.
- Authenticated app routes in `routes/web.php`: `dashboard`, `services/create`, `requests/create`, `transactions`, `messages`, `points`, `favorites`, `reports`, `profile`, `loops`, and their nested actions.
- Admin routes under `/admin/*`, including admin `loops`, `communities`, `users`, `settings`, `email-*`, and moderation URLs.
- API routes under `routes/api.php`, including `/api/services`, `/api/requests`, `/api/users`, `/api/transactions`, and `/api/auth/*`.
- Broadcast channel definitions in `routes/channels.php` are internal runtime authorization and not public route targets.
- Console routes in `routes/console.php` are not HTTP routes.

## Routes Legacy Techniques Temporaires

- Dynamic tenant prefix routes use an existing legacy identifier in code and remain a compatibility layer during Organization migration.
- These routes must not be treated as global public pages; public is not global.
- Future redirects must preserve the resolved Organization context and must not collapse tenant-prefixed URLs to root URLs unless product explicitly wants root canonicalization.
- Legacy naming should not be expanded in product notes; use Organization / Loop / Member / Partner for new documentation.

## Routes Déjà Remplacées par Équivalent Français

- Partner public index: `/partenaires` replaces any intended English `/partners` public target.
- Partner request intake: `/partenaires/demande` replaces any intended English Partner request target.
- Loop public concept page: `/boucles` is the canonical French public URL; `/loops` must not be positioned as its public equivalent.

---

# Redirect / Removal Strategy

Recommended sequence for a later runtime task:

1. Add explicit reserved English public redirects before the dynamic tenant slug route.
2. Start with `/partners` -> `/partenaires` as 301, plus tenant slug reservation for `partners` to prevent Organization slug ambiguity.
3. Decide `/loops` separately: prevent it from being a public target, but do not break authenticated Loop runtime routes without a dedicated app URL migration.
4. Add `/search` -> `/recherche` only if `/recherche` is introduced or confirmed as a French canonical route.
5. Defer public detail URL renames (`/requests/{request}`, `/profile/{user}` and tenant-prefixed variants) until Organization-scoped binding and Member wording are reviewed.
6. Update tests and SEO artifacts in the runtime task: route tests, tenant isolation tests, sitemap/canonical checks, and Playwright smoke checks for redirects.

Preferred treatment by route family:

- Immediate safe candidate: `/partners` -> `/partenaires` 301.
- Conditional candidate: `/search` -> `/recherche` after adding a French canonical target.
- Deferred high-safety candidates: public detail routes for requests and Member profiles.
- Conservation interne: authenticated `/loops` and API/admin/auth routes until a dedicated internal URL migration exists.

---

# Hors Scope T076.2

- No runtime redirect implementation.
- No change to `routes/web.php` or any route file.
- No controller, middleware, model, policy, Blade, migration, config, or test code changes.
- No route cache inspection requiring app mutation.
- No sitemap or canonical tag modification.
- No public UI copy changes.
- No authenticated app URL migration.
- No API versioning or API URL localization.
- No Organization route prefix migration.
- No tenant middleware refactor.

---

# Proposition de tâche suivante

Recommended next task: `T076.3 — Public English Redirect Runtime Patch`.

Suggested T076.3 scope:

- implement explicit `/partners` -> `/partenaires` 301 before dynamic tenant resolution
- reserve `partners` in the dynamic tenant slug exclusion list
- add route tests proving `/partners` cannot resolve as an Organization slug
- decide whether `/loops` needs an unauthenticated public guard/redirect without breaking authenticated Loop routes
- optionally add `/search` -> `/recherche` only if `/recherche` is created as a French canonical page in the same or prior task
- leave tenant-prefixed public detail route localization for a later Organization-safe migration task

---

# Progress Log

## 2026-05-18 11:37:00 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-101-t076-2-public-english-routes-debt-audit-redirect-strategy

Status:
IN_PROGRESS

## 2026-05-18 11:39:05 Europe/Paris

Completed documentation-only audit of route files.

Findings:

- `/partenaires`, `/partenaires/demande`, `/boucles`, and `/boucles/creer` are present from T076.1.
- `/partners` is not explicit, but can collide with the dynamic tenant legacy slug path because `partners` is not reserved.
- `/loops` is currently authenticated app runtime, not a public marketing target equivalent to `/boucles`.
- Remaining public English debt is mostly `/search`, `/requests/{request}`, `/profile/{user}`, `blog/tag`, and tenant-prefixed public detail equivalents.
- API/admin/auth/internal application URLs are not in T076.2 public marketing scope.

Modified files:

- `TODO/TASK-101-t076-2-public-english-routes-debt-audit-redirect-strategy.md`

Runtime files modified:

- none

## 2026-05-18 11:50:36 Europe/Paris

Finalization prepared after external review.

Review outcome:

- OPENAI: APPROVE WITH NOTES.
- No architectural blocker reported.
- Notes accepted for the follow-up runtime task: keep `/partners` as the first safe redirect candidate, avoid treating authenticated `/loops` as a public `/boucles` equivalent, and keep tenant-prefixed public detail redirects deferred until Organization-safe binding is reviewed.

Status:

- DONE

Lock:

- UNLOCKED

Modified files:

- `TODO/TASK-101-t076-2-public-english-routes-debt-audit-redirect-strategy.md`

Runtime files modified:

- none

# Handoffs

No handoff. Task finalized and unlocked by OPENCODE.

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation
- [x] documentation-only review

---

# Test Results

Not run. T076.2 is documentation-only and no runtime code was modified.

---

# Review Notes

Documentation-only audit completed. The highest priority runtime debt is `/partners`, because it has no explicit public route but can still be interpreted as a tenant slug before Organization resolution. `/loops` should not be handled as a simple public redirect until authenticated Loop runtime URLs are separated from public `/boucles` semantics.

## OPENAI Review

Outcome: APPROVE WITH NOTES.

Notes integrated for follow-up work:

- No architectural blocker was identified.
- Keep T076.3 runtime scope narrow and explicit.
- Prefer `/partners` -> `/partenaires` as the first redirect candidate, with tenant slug reservation to avoid Organization slug ambiguity.
- Do not redirect authenticated `/loops` to public `/boucles` without a dedicated internal app URL migration.
- Defer tenant-prefixed public detail URL localization until Organization-scoped binding and visibility tests are defined.
