# Changelog

## v0.130-alpha - Develop stabilization

Status: merged in `develop`; not yet merged in `main`; not yet deployed to production.

This entry summarizes the stabilization train after the T073 production backport and before any future `main` / PROD update. It is based primarily on `TODO/TASK-*.md`, recent audit reports, and the current `origin/main...origin/develop` branch state.

### Tenant / Organization scope

- T075 established the canonical rule: **Organization = Tenant**; Loop and Partner are not tenants.
- T078 through T096 migrated the runtime toward organization-native tenant resolution while preserving the legacy `Community` / `community_id` compatibility layer.
- T122 backfilled legacy NULL `community_id` records to the default organization.
- T124 through T129 audited and reduced tenant-scope residual risk:
  - residual `withoutGlobalScope` surfaces were classified;
  - Explorer tampering and Profile reviews cross-organization risks were covered and fixed;
  - `community_id` / `organization_id` source-of-truth divergence was documented;
  - the `withoutGlobalScope` allowlist was formalized.
- T132 replaced a broad `withoutGlobalScopes()` usage in `HomeController` with a targeted `withoutGlobalScope(BelongsToTenantScope::class)` pattern and updated the allowlist.

Known state: the runtime is still in a compatibility phase. `BelongsToTenantScope` filters on `community_id`; policies generally reason on `organization_id`; `HasOrganizationId` synchronizes both columns for Eloquent writes. Do not rename `Community` globally or switch the tenant scope column without a dedicated migration and data-validation plan.

### Public surfaces

- Public does not mean global. Root-domain and partner-prefixed business routes now resolve an Organization context.
- T099 through T102 clarified public French/English routing behavior and redirected legacy English public partner routes.
- T123 hardened blog and public surface tenant scoping.
- Public Boucles surfaces remain controlled; `/boucles` is not a broad public DB listing.

### Boucles / Loops

- T074.3 through T074.10 introduced Loop domain foundations, loop creation, loop messages, member UI, IA-assisted help requests, OrgAdmin loop/message centers, and calm activity indicators.
- T077.0 through T077.4 documented and extended the Boucles product surface, membership/visibility MVP, and product doctrine.
- Loop storage still uses `loops.community_id` as legacy parent storage. This is explicitly not a tenant definition: **Loop != Tenant**.
- New Loop migrations exist on `develop` and are not yet in `main`:
  - `2026_05_15_000001_create_loops_table.php`
  - `2026_05_15_000002_create_loop_members_table.php`
  - `2026_05_15_000003_create_loop_messages_table.php`
  - `2026_05_18_000001_add_visibility_to_loops_table.php`

### Tests / CI

- T121 fixed widespread Feature-suite CSRF 419 failures by disabling the actual Laravel 11 `PreventRequestForgery` middleware in tests.
- T126 added P0 tenant-scope regression tests.
- T127 fixed the P0/P1 tenant isolation regressions identified by those tests.
- T131 confirmed the SQLite batch is stable: 725 tests / 1585 assertions, two consecutive full runs, with no active SQLite-specific issue.
- PostgreSQL compatibility remains a release-readiness requirement before any future PROD update.

### Prod-local sync

- T114 through T116 documented branch state, prod-local sync strategy, and safe sync preflight guard.
- T121 repaired production mirror import ordering and synchronized production data locally for validation context.
- No future release step should run `pg-dump`, apply migrations, or execute Laravel Cloud commands automatically from this changelog work.

### Documentation / agents

- T110 through T113 reorganized documentation, slimmed multi-tenant agent context, and documented CAO routing/workflow.
- Architecture docs now emphasize Organization as the tenant boundary and preserve compatibility-first migration discipline.
- Recent audit docs to keep visible during release readiness:
  - `docs/audits/T124-tenant-scope-residual-risk-audit.md`
  - `docs/audits/T128-tenant-id-source-of-truth-strategy.md`
  - `docs/audits/T131-sqlite-batch-stability-audit.md`
  - `docs/architecture/withoutGlobalScope-allowlist.md`
  - `docs/architecture/02-PROD_LOCAL_SYNC_STRATEGY.md`

### Branch cleanup / versioning

- T130 cleaned up 70 remote branches and set the footer/application version to `v0.130-alpha`.
- `develop` currently points to the T132 merge.
- `main` currently points to the T073 pre-T074 release commit.
- `develop` and `main` are diverged; this changelog does not merge or modify `main`.

### Known limitations

- `develop` contains 234 commits not in `origin/main`; `origin/main` contains 3 release/backport commits not in `origin/develop`.
- Loop migrations are new relative to `main` and require a separate PROD DB migration plan.
- `loops` does not currently have an `organization_id` column.
- Tenant source-of-truth is still dual-write / compatibility mode (`community_id` plus `organization_id` on most tenant-scoped models).
- Some local and remote branches remain after T130 and require manual arbitration; no branch was deleted for this changelog.
- This release-readiness pass is documentation-only. It did not run tests, migrations, database commands, Laravel Cloud commands, or production operations.

### Not deployed to production yet

All items above describe the current `develop` state. They are not asserted to be live in `main` or PROD. A future release-readiness review must separately validate CI, PostgreSQL migrations, tenant isolation, prod DB migration order, rollback posture, and Laravel Cloud deployment steps before any production update.
