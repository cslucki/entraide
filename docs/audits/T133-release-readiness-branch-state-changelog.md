# T133 - Release Readiness / Branch State / Changelog

Date: 2026-05-24 09:48:45 Europe/Paris  
Branch: `TASK-133-t133-release-readiness-branch-state-changelog`  
Mode: documentation-only audit; no `main` modification; no PROD action; no migration execution

## 1. Executive summary

TASK-133 opened a release-readiness cockpit for the future `main` / PROD update. The repository is currently on a task branch created from `develop`.

`develop` is ahead of `main` by the full T074 -> T132 stabilization train and includes tenant hardening, Boucles/ChatLoop work, public-surface routing changes, test stabilization, prod-local sync documentation, branch cleanup, and version footer `v0.130-alpha`.

`main` is still at the T073 pre-T074 release line. This task did not modify `main`, did not touch PROD, did not run migrations, did not run Laravel Cloud commands, and did not delete branches.

Primary outputs:

- root `CHANGELOG.md`
- this release-readiness audit
- completed `TODO/TASK-133-t133-release-readiness-branch-state-changelog.md`

## 2. Branch state

### Protected / current references

| Reference | Commit | Meaning |
| --- | --- | --- |
| `HEAD` | `7f75871` | Current TASK-133 branch base, created from `develop` |
| `origin/develop` | `7f75871` | Latest remote develop, merge of T132 |
| `develop` | `7f75871` | Local develop matches origin/develop |
| `origin/main` | `b392a13` | T073 pre-T074 release line |
| `main` | `b392a13` | Local main matches origin/main |

Merge-base inspected: `5c4132c3b596033410b4336921080edf07528a82`.

### Gap develop -> main

Command: `git rev-list --left-right --count origin/main...origin/develop`

Result:

- `origin/main` only: 3 commits
- `origin/develop` only: 234 commits

Interpretation:

- A future release cannot be treated as a trivial fast-forward without reviewing the 3 `main`-only release/backport commits.
- The large `develop` side contains T074 -> T132 work, including DB migrations and tenant-safety changes.
- TASK-133 does not merge these branches.

### Local branches

Local branches are numerous. The important buckets are:

CAO branch audit count: 107 local branches.

Protected / keep:

- `main`
- `develop`
- `TASK-133-t133-release-readiness-branch-state-changelog`

Likely keep / review intentionally:

- `release-t073-prod-backport`
- `develop-clean`
- `OPS/pg-sync-20260523-154115`
- `ai/recovery`

Merged into `origin/develop` and candidates for later local cleanup only after manual confirmation:

- T074 Loop/ChatLoop implementation branches already merged into develop
- TASK-051 through TASK-117 historical workflow/product branches that are merged
- TASK-122, TASK-124, TASK-125, TASK-126, TASK-127, TASK-128
- assorted local scratch branches: `test-redirect`, `vk/*`

Local branches not merged into `origin/develop` and requiring manual arbitration:

- `ALPHA-SETUP-01-alpha1-setup`
- `T074.1-t074-1-ux-ergonomics-chatloop-mobile-desktop-admin`
- `T074.1A-t074-1a-ia-solution-spike-chatloop-assisted-interactions`
- `TASK-073E-referral-reward-configuration`
- `TASK-075-lt-003-corriremos-scripts-sous-t-ches-t074-x`
- `TASK-076-lt-004-tooling-support-for-explicit-subtask-creation`
- `TASK-092-t075-13-runtime-current-community-removal-pass`
- `TASK-093-t075-14-organization-first-test-fixtures-legacy-community-imports-cleanup`
- `TASK-095-t075-16-resolve-url-organization-legacy-fallback-reduction`
- `TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup`
- `TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit`
- `TASK-106-t078-0a-admin-ia-design-lab-real-openai-smoke-test`
- `TASK-129-t129-withoutglobalscope-allowlist-guard-tests`
- `TASK-130-t130-branch-cleanup-version-footer`
- `TASK-131-t131-sqlite-batch-stability-audit`
- `TASK-132-t132-homecontroller-withoutglobalscopes-targeted-cleanup`
- `release-t073-prod-backport`
- `sandbox-test-t073-pre-t074`

No local branch was deleted.

### Remote branches

Remote branches currently visible:

CAO branch audit count: 21 remote refs, including `origin/HEAD`.

- `origin/main`
- `origin/develop`
- `origin/HEAD`
- `origin/release-t073-prod-backport`
- `origin/ALPHA-SETUP-01-alpha1-setup`
- `origin/T074.1-t074-1-ux-ergonomics-chatloop-mobile-desktop-admin`
- `origin/T074.1A-t074-1a-ia-solution-spike-chatloop-assisted-interactions`
- `origin/TASK-073E-referral-reward-configuration`
- `origin/TASK-075-lt-003-corriremos-scripts-sous-t-ches-t074-x`
- `origin/TASK-076-lt-004-tooling-support-for-explicit-subtask-creation`
- `origin/TASK-092-t075-13-runtime-current-community-removal-pass`
- `origin/TASK-093-t075-14-organization-first-test-fixtures-legacy-community-imports-cleanup`
- `origin/TASK-094-t075-15-fillable-tenantless-validation-guard`
- `origin/TASK-095-t075-16-resolve-url-organization-legacy-fallback-reduction`
- `origin/TASK-096-t075-17-admin-policies-api-legacy-community-imports-cleanup`
- `origin/TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit`
- `origin/TASK-106-t078-0a-admin-ia-design-lab-real-openai-smoke-test`
- `origin/TASK-129-t129-withoutglobalscope-allowlist-guard-tests`
- `origin/TASK-130-t130-branch-cleanup-version-footer`
- `origin/TASK-131-t131-sqlite-batch-stability-audit`
- `origin/TASK-132-t132-homecontroller-withoutglobalscopes-targeted-cleanup`

Remote cleanup classification:

- Keep: `origin/main`, `origin/develop`, `origin/release-t073-prod-backport` until release strategy is settled.
- Delete later candidate: `origin/TASK-094-t075-15-fillable-tenantless-validation-guard` appears merged into `origin/develop`.
- Review later: all other task/remnant remote branches listed above. T130 deliberately cleaned many branches, but these remain and should not be deleted automatically from TASK-133.

## 3. Changelog synthesis

Root `CHANGELOG.md` now summarizes `v0.130-alpha - Develop stabilization` by theme:

- Tenant / Organization scope
- Public surfaces
- Boucles / Loops
- Tests / CI
- Prod-local sync
- Documentation / agents
- Branch cleanup / versioning
- Known limitations
- Not deployed to production yet

The changelog deliberately avoids per-commit listing. It distinguishes:

- merged in `develop`
- not yet merged in `main`
- not yet deployed to production

## 4. Migration state

Command: `git diff --name-status origin/main..origin/develop -- database/migrations`

New migrations on `develop` relative to `main`:

| Migration | Purpose | PROD sensitivity |
| --- | --- | --- |
| `2026_05_15_000001_create_loops_table.php` | Creates `loops` with UUID PK, `community_id` FK, `created_by` FK, unique `(community_id, slug)` | Introduces new tenant-adjacent table using legacy parent storage |
| `2026_05_15_000002_create_loop_members_table.php` | Creates memberships with unique `(loop_id, user_id)` | Access-control critical; must preserve same-tenant membership invariants |
| `2026_05_15_000003_create_loop_messages_table.php` | Creates messages with `loop_id`, nullable `sender_id`, JSON metadata, indexes | Data volume and privacy sensitive; messages inherit tenant through parent Loop |
| `2026_05_18_000001_add_visibility_to_loops_table.php` | Adds `visibility` default `private` | Semantics sensitive; `active` or `visibility` must not be treated as public without policy |

Loop migrations:

- All four new migrations are Loop-related.
- `loops` currently has `community_id`, not `organization_id`.
- This is compatible with the current migration phase but must be treated as legacy parent storage, not as a new tenant concept.

Tenant-scope migrations:

- No additional tenant-scope migration files were detected in `database/migrations` between `origin/main` and `origin/develop` beyond the Loop set above.
- Tenant runtime changes after T073 are mostly code/tests/docs, plus T122 data-backfill behavior in application/seeder logic.

Potential PROD-sensitive points:

- FK order matters: `loops` before `loop_members` and `loop_messages`.
- Existing production data must have valid `communities` and `users` references before Loop rows are introduced.
- `visibility` default is private; public exposure must not be inferred automatically.
- `Loop`, `LoopMember`, and `LoopMessage` do not register `BelongsToTenantScope`; current isolation depends on explicit controller/service/channel checks.
- The database does not enforce that `loop_members.user_id` belongs to the same `community_id` as the parent `loops.community_id`; direct inserts/imports must preserve that invariant.
- CAO flagged a future performance hardening candidate: `loop_members` has `unique(loop_id, user_id)` but no standalone `user_id` index for membership lookups.
- No migration should be run from TASK-133.

## 5. Future main / PROD preparation

Must verify before any future `main` update:

- exact reconciliation plan for the 3 `main`-only commits vs 234 `develop`-only commits;
- full CI status on release candidate branch;
- PostgreSQL migration dry-run in a non-PROD environment;
- SQLite and PostgreSQL test parity;
- tenant isolation regression suite, especially T126/T127/T129/T132 areas;
- Loop access-control behavior: tenant boundary, membership, messages, public `/boucles`;
- rollback plan for new Loop tables;
- backup and deployment windows handled outside this documentation task.

Must not be done automatically:

- do not merge into `main`;
- do not push `main`;
- do not run `php artisan migrate` against PROD or prod-like DBs;
- do not run `pg-dump` / prod mirror commands;
- do not run Laravel Cloud commands;
- do not delete branches without an explicit branch-cleanup task;
- do not rename `Community` globally;
- do not change footer version from TASK-133.

Commands explicitly forbidden without separate validation:

- `php artisan migrate`
- `php artisan migrate:fresh`
- `php artisan db:wipe`
- `ai/scripts/pg-dump.sh`
- any Laravel Cloud deploy or database command
- direct push to `main`

## 6. CAO usage

Initial CAO launches failed because `cao-server` was not running on `127.0.0.1:9889`.

`cao-server` was then started locally with:

- `env TERM=xterm-256color cao-server --port 9889`

Read-only CAO sessions launched sequentially:

| Agent | Session | Terminal | Status at document creation |
| --- | --- | --- | --- |
| Branch state audit | `cao-t133-branch-state-audit` | `audit-scope-policies-3757` | launched |
| TASK changelog audit | `cao-t133-task-changelog-audit` | `audit-scope-policies-3176` | launched |
| Migration / PROD readiness audit | `cao-t133-migration-prod-readiness-audit` | `audit-scope-policies-62df` | launched |
| Documentation / release consistency audit | `cao-t133-doc-release-consistency-audit` | `audit-scope-policies-b4d9` | launched |

Codex/OpenCode remained the only writer. CAO was instructed not to modify files.

Final CAO result:

- Branch audit: succeeded. Confirmed `HEAD == origin/develop`, `origin/main...origin/develop = 3 / 234`, 107 local branches, 21 remote refs, and 19 local / 19 remote refs not merged into `origin/develop`.
- TASK changelog audit: succeeded. Confirmed the major task ranges used in `CHANGELOG.md`: T074/T077 Boucles, T075/T122-T132 tenant hardening, T079/T080 docs/sync, T130 branch cleanup/versioning.
- Migration / PROD readiness audit: succeeded. Confirmed only four develop-not-main migration files, all Loop-related. Flagged explicit-scope dependency, DB membership invariant gap, cascade sensitivity, and possible `loop_members.user_id` index follow-up.
- Documentation consistency audit: succeeded. Confirmed core doctrine consistency and flagged stale index/status items: `docs/README.md` does not yet list T123/T124/T128/T131/allowlist docs, and T123 audit still reads as non-merged historical status.

No CAO agent modified files.

## 7. Recommended next step

Recommendation: do not merge to `main` from this task.

Next useful task should be one of:

- release readiness review: reconcile `main`-only release commits and build a candidate plan;
- PROD DB migration plan: Loop tables, rollback, backup, and dry-run sequencing;
- product work can resume separately if main/PROD remains deferred.
