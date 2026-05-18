---
task_id: TASK-105
title: t077-2-boucles-organization-scoped-runtime-audit-strategy

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-105-t077-2-boucles-organization-scoped-runtime-audit-strategy

priority: MEDIUM

created_at: 2026-05-18 19:35:00 Europe/Paris
updated_at: 2026-05-18 20:37:06 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: MERGED
  url: null
---

# Objective

Audit the existing technical Loop runtime before any new public DB exposure, then produce an Organization-scoped strategy for T077.3.

This task is audit / strategy only. No runtime feature code, migration, public DB route, ChatLoop, AI, websocket, heavy refactor, finalize or merge.

---

# Planned Actions

- [x] inspect Loop models and storage
- [x] inspect `/loops`, `/boucles`, admin and legacy-prefixed routes
- [x] inspect controllers, services, policies, tests, views and broadcast channel
- [x] identify legacy dependencies, Organization dependencies and exposure risks
- [x] produce audit document for T077.3 strategy
- [x] update task file with actions, decisions, risks, tests and handoff notes

---
# Progress Log


## 2026-05-18 19:35:00 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-105-t077-2-boucles-organization-scoped-runtime-audit-strategy

Status:
IN_PROGRESS

## 2026-05-18 19:42:16 Europe/Paris

Audit performed by OPENCODE on branch `TASK-105-t077-2-boucles-organization-scoped-runtime-audit-strategy`.

Files inspected:

- `app/Models/Loop.php`
- `app/Models/LoopMember.php`
- `app/Models/LoopMessage.php`
- `app/Models/User.php`
- `app/Models/Community.php`
- `database/migrations/2026_05_15_000001_create_loops_table.php`
- `database/migrations/2026_05_15_000002_create_loop_members_table.php`
- `database/migrations/2026_05_15_000003_create_loop_messages_table.php`
- `database/factories/LoopFactory.php`
- `database/factories/LoopMemberFactory.php`
- `database/factories/LoopMessageFactory.php`
- `routes/web.php`
- `routes/channels.php`
- `app/Http/Controllers/LoopController.php`
- `app/Http/Controllers/Admin/AdminLoopController.php`
- `app/Http/Controllers/HomeController.php`
- `app/Services/LoopService.php`
- `app/Services/LoopMessageService.php`
- `app/Events/LoopMessageCreated.php`
- `app/Support/Tenancy/CurrentOrganization.php`
- `bootstrap/app.php`
- `resources/views/loops/index.blade.php`
- `resources/views/loops/show.blade.php`
- `resources/views/loops/create.blade.php`
- `resources/views/admin/loops/index.blade.php`
- `resources/views/boucles/index.blade.php`
- `tests/Feature/LoopModelTest.php`
- `tests/Feature/LoopCreationTest.php`
- `tests/Feature/LoopMessageTest.php`
- `tests/Feature/LoopHelpRequestTest.php`
- `tests/Feature/LoopMemberInvariantTest.php`
- `tests/Feature/LoopActivityTrackingTest.php`
- `tests/Feature/Admin/AdminLoopsTest.php`
- `tests/Feature/T07411RoutesTenantSafetyTest.php`
- `tests/Feature/PublicFrenchPartnersRoutesTest.php`

Actions completed:

- Inspected Loop schema, models, relations, factories and runtime services.
- Inspected public `/boucles`, authenticated `/loops`, admin `/admin/loops`, legacy-prefixed `/{community}/loops/*` routes and private broadcast channel authorization.
- Inspected existing tests for membership, cross-tenant isolation, route safety, admin scoping and public French routes.
- Created `docs/audits/T077.2-boucles-organization-scoped-runtime-audit-strategy.md`.

Decisions:

- T077.2 remains documentation-only and audit-only.
- `/boucles` must not read true Loop DB records until T077.3 defines Organization-scoped visibility semantics.
- Existing `loops.community_id` is documented as legacy DB storage only; new T077.3 strategy must use Organization as tenant concept.
- Membership remains the existing reliable access boundary for internal Loop `show`, messages and mutations.
- `status = active` is not a public visibility contract.

Risks identified:

- `Loop` has no canonical `organization_id` field and no explicit visibility field.
- `Route::get('/loops/{loop}')` uses normal route-model binding before controller tenant/membership checks.
- Root-domain `/loops` falls back to the authenticated user's tenant profile when no URL Organization is present.
- No `LoopPolicy` exists; authorization is distributed across controller, services and channel callback.
- `loop_messages` and `loop_members` have no direct tenant field and must inherit tenant safety from `Loop`.
- Public exposure of real DB Loops via `/boucles` would be premature without fail-closed Organization resolution.

Recommended T077.3 strategy:

- Implement a targeted Boucles Visibility & Membership MVP.
- Require an Organization context before any public DB read.
- Keep internal Loop access based on active membership.
- Do not expose messages, member roster, referrals, creator email, ChatLoop, AI or websocket on public surfaces.
- Add tests for visitor, member, Organization admin, Loop member, non-member, cross-Organization UUID access and root-domain tenantless behavior.
- Avoid broad legacy terminology migration inside T077.3; document the existing storage debt only.

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation by static audit

---

# Test Results

No automated tests were run for T077.2 because the task is audit / strategy only and changed documentation plus the TASK file only.

Static validation performed:

- Inspected Laravel routes, models, controllers, services, migrations, views, tests and broadcast channel definitions.
- Queried database schema for `loops`, `loop_members`, and `loop_messages` via Laravel Boost schema tool.

---

# Review Notes

Audit document created at `docs/audits/T077.2-boucles-organization-scoped-runtime-audit-strategy.md`.

T077.3 must stay fail-closed: no true public Loop DB exposure without Organization resolution and an explicit visibility contract. Existing membership protections are useful for authenticated internal Loop access, but are not sufficient alone for public `/boucles` runtime.

## 2026-05-18 20:37:06 Europe/Paris

Merge officiel effectué par OPENCODE via `./ai/scripts/merge-task.sh TASK-105`.

État:
- Merge dans `develop`: OK.
- Push `develop`: OK.
- Merge commit: `9347026`.
- Status passé à `MERGED`.
- Lock conservé `UNLOCKED`, lock.since nullifié.
- Branche `main` et PROD non touchés.
- Branche distante tâche conservée.
