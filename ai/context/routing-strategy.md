# Organization-Aware Routing Strategy

## Root Domain Routing Patterns (T075.1)

### Pattern cible

| Pattern | Comportement |
|---------|-------------|
| `/{feature}` | Résout l'Organization par défaut de la plateforme. Ex: `/blog`, `/explorer`, `/membres`. |
| `/{partnerSlug}/{feature}` | Résout l'Organization partenaire. Ex: `/bni/blog`, `/bni/explorer`. |
| `/partners` | Platform global. Pas d'Organization. |
| `/{partnerSlug}` | Public mais Organization-scopé (Needs Org = Yes, Global = No). |

### Règles

- **Public ≠ global.** Une route publique peut être Organization-scopée. Ex: `/{partnerSlug}` est publique mais nécessite une Organization résolue.
- **`/boucles` est legacy** à déprécier, pas le vrai concept Loop.
- **Toute feature métier actuelle et future** doit être Organization-scopée. Aucune feature métier sur route Platform globale par défaut.

### Routes Platform globales autorisées

`/`, `/login`, `/register`, `/password/*`, `/mentions-legales`, `/sitemap.xml`, `/partners`, `/admin/*`.

---

## Current Architecture

### Two URL Scheme Patterns

**Platform global routes**: `/`, `/login`, `/register`, `/password/*`, `/partners`, `/admin/*`.

**Legacy root-domain business routes currently lacking tenant middleware**: `/dashboard`, `/services/{uuid}`, etc. These are not architecturally global; T075.2 must resolve an Organization for them.

**Community-scoped routes**: `/{community}/...` with middleware `['web', 'community']`

Reserved by `$communityConstraint` negative lookahead:
`login`, `register`, `admin`, `api`, `sitemap`, `search`, `explorer`, `profile`, `password`, `membres`, `echanges`, `boucles`

### Route Resolution

```
/{community}/services/{service}
         ↓ Middleware: ResolveCommunity
         ↓ Community::findBySlug($slug)
         ↓ app()->instance('current_community', $community)
         ↓ app()->instance('current_organization', $community)
         ↓ View::share('currentCommunity', $community)
         ↓ View::share('currentOrganization', $community)
```

The middleware resolves the slug manually (no implicit route model binding).
Community model uses `id` as default route key; Organization model uses `slug`.

### Middleware Aliases (`bootstrap/app.php`)

```php
'community' => ResolveCommunity::class,
'organization' => ResolveOrganization::class,
```

`ResolveOrganization` extends `ResolveCommunity` — same behavior, semantic alias.

---

## Future Dual Routing Plan

### Target State

```
/org/{organization}/services/{service}
         ↓ Middleware: organization
         ↓ ResolveOrganization (extends ResolveCommunity)
         ↓ Organization::findBySlug($slug) [via parent]
         ↓ app()->instance('current_organization', $org)
         ↓ app()->instance('current_community', $org)  [backward compat]
```

Route group definition:
```php
Route::prefix('/org/{organization}')
    ->middleware(['web', 'organization'])
    ->where(['organization' => $organizationConstraint])
    ->name('organization.')
    ->group(function () {
        // Mirrors current community.* routes
    });
```

### Migration Order (per Project Rules)

1. database — already done (TASK-059)
2. models — already done (TASK-066)
3. middleware — already done (TASK-069)
4. routes — THIS TASK (preparation only)
5. controllers — future
6. policies — future
7. Livewire — future
8. views — future
9. PHPUnit — future
10. Playwright — future

---

## Helper Abstraction Contract

### `organizationRoute(string $name, array $parameters = []): string`

Wraps `route()` with transparent parameter mapping.

**Current behavior**: Accepts both `'organization'` and `'community'` as route params,
maps both to `community.*` routes.

**Future behavior** (when `/org/{organization}` routes are active):
Config-switchable to generate `/org/{organization}/...` instead of `/{community}/...`.

### `currentOrganization(): ?Model`

Returns the current tenant instance (Organization or Community).
Already provides the fallback chain: `current_organization` → `current_community` → null.

---

## Migration Checklist (Future)

### Phase 1 — Preparation (TASK-071)
- [x] Audit routing architecture
- [x] Fix hardcoded Blade URLs → named routes
- [x] Add `organizationRoute()` helper
- [x] Document strategy

### Phase 2 — `/org/{organization}` route group activation
- [ ] Duplicate community.* routes as organization.* routes
- [ ] Point organization middleware to new route group
- [ ] Keep community.* routes active alongside
- [ ] Add canonical URL logic for SEO
- [ ] Update sitemap to include both URL schemes

### Phase 3 — Auth redirect migration
- [ ] Switch login/register redirects to use `organizationRoute()` helper
- [ ] Update CommunityLandingController to support `/org/` URLs

### Phase 4 — Playwright alignment
- [ ] Update `tests/e2e/community-transactions/helpers/config.js` COMMUNITY_ROUTES
- [ ] Update `tests/e2e/community-transactions/helpers/community.js` goTo* functions
- [ ] Add /org/{organization} test coverage
- [ ] Run full 16-spec matrix

### Phase 5 — SEO & Canonical
- [ ] Add `<link rel="canonical">` for dual URL schemes
- [ ] Update sitemap for organization-scoped URLs
- [ ] Configure redirects from /{community} → /org/{organization}

### Phase 6 — Route migration
- [ ] Deprecate /{community} routes (keep redirect)
- [ ] Remove community middleware alias
- [ ] Clean up backward compatibility in Middleware

---

## Playwright Impact Assessment

**16 test files** construct community URLs via path concatenation.

Current pattern (in `config.js`):
```js
COMMUNITY_ROUTES = {
  dashboard: (slug) => `/${slug}/dashboard`,
  services: (slug) => `/${slug}/services`,
  // ... 14 functions total
}
```

These are used across all workflow tests (QA-01 through QA-MT02).

**Safety recommendation**: Do NOT update Playwright URLs in this TASK.
Playwright changes are Phase 4 in the migration plan above.

---

## Risk Register

| Risk | Severity | Mitigation |
|------|----------|------------|
| SEO regression (reindexing) | High | Canonical URLs, 301 redirects from old patterns |
| Playwright test failures | High | Phase 4 — separate task |
| Auth redirect 404 after migration | Medium | Test all auth flows in each phase |
| Hardcoded URLs in Blade | Medium | Fixed in TASK-071 |
| Cross-org data leak via global routes | Medium | Future scoping enforcement |
| Community model route key confusion | Low | Middleware handles resolution manually |
