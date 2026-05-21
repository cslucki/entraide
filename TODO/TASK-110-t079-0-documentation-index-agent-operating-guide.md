---
task_id: TASK-110
title: T079.0 — Documentation Index & Agent Operating Guide

status: DONE

owner: OPENCODE

contributors:
  - OPENAI

branch: TASK-110-t079-0-documentation-index-agent-operating-guide

priority: MEDIUM

created_at: 2026-05-21 13:33:24 Europe/Paris
updated_at: 2026-05-21 16:12:18 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Open T079.0 as a clean documentation task and branch for the Documentation Index & Agent Operating Guide patch.

Scope extended during review from minimal banners/indexes to a controlled documentation refactor of `docs/` and `ai/`.

This remains documentation-only.

---

# Planned Actions

- [x] start from `develop`
- [x] run `git pull --ff-only origin develop`
- [x] verify clean git status before task creation
- [x] create task and branch with `ai/scripts/create-task.sh`
- [x] run `ai/scripts/check-task.sh` and document result
- [x] prepare TASK file only
- [x] OPENAI / Codex applies documentation patch
- [x] OPENAI / Codex extends T079.0 into controlled `docs/` / `ai/` documentation refactor
- [x] validate documentation-only diff scope
- [x] update task status and lock according to final review workflow

Out of scope for this OPENCODE setup pass:

- no runtime changes
- no merge

Out of scope for the extended T079.0 patch:

- no `app/`
- no `routes/`
- no `resources/`
- no `database/`
- no `config/`
- no `ROADMAP.md`
- no `@DOCS/`
- no deletion of historical audits
- no recreation of T077.4
- no finalization
- no runtime changes
- no merge

---
# Progress Log


## 2026-05-21 13:33:24 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-110-t079-0-documentation-index-agent-operating-guide

Status:
IN_PROGRESS

## 2026-05-21 13:33:50 Europe/Paris

OPENCODE setup pass for T079.0.

Actions completed:

- confirmed starting branch was `develop`
- ran `git pull --ff-only origin develop`; develop already up to date
- verified clean git status before task creation
- created task and branch with `ai/scripts/create-task.sh "T079.0 — Documentation Index & Agent Operating Guide" OPENCODE`
- created branch `TASK-110-t079-0-documentation-index-agent-operating-guide`
- created TASK file `TODO/TASK-110-t079-0-documentation-index-agent-operating-guide.md`
- ran `ai/scripts/check-task.sh`

`check-task.sh` result:

- CHECK FAILED, expected for a newly opened task because status is `IN_PROGRESS`, lock is `LOCKED`, and the TASK file was still uncommitted at that point

Scope guardrails:

- no `docs/` content changed
- no `ai/` content changed outside this TASK file
- no `app/`, `routes/`, `resources/`, `database/`, or `config/` changes
- no merge

Next owner/action:

- OPENAI / Codex may apply the reviewed documentation patch on this branch

## 2026-05-21 14:00:23 Europe/Paris

OPENAI / Codex applied the minimal T079.0 documentation clarification patch.

REVIEW verdict carried into this patch:

- `docs/` is the canonical project memory: what the project is.
- `ai/` is the agent operating layer: how agents work.
- `@DOCS/` remains private human documentation, non-tracked, non-committed, and not a repository source for agents.
- Documentary risk was high because `docs/` and `ai/context/` could be read as parallel truth sources.
- Minimal clarification through `docs/README.md`, `ai/README.md`, targeted banners and two `/boucles` superseded notes reduces the risk without a broad documentation refactor.

Files modified:

- `docs/README.md`
- `ai/README.md`
- `docs/01-UI_RULES.md`
- `docs/03-COMPONENT_LIBRARY.md`
- `docs/04-ENGINEERING_RULES.md`
- `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md`
- `docs/05-DOMAIN_ARCHITECTURE.md`
- `docs/06-GLOSSARY.md`
- `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md`
- `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md`
- `docs/specs/01-T074.2-chatloop-center-ia-assisted-interactions.md`
- `docs/specs/02-T077.0-boucles-product-surface-spec.md`
- `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md`
- `docs/audits/T074-assets-index.md`
- `docs/audits/T074.0-technical-audit-current-messaging-mobile-reverb-readiness.md`
- `docs/audits/T074.1-ux-chatloop-mobile-desktop-admin.md`
- `docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md`
- `docs/audits/T075.0-organization-native-tenant-audit.md`
- `docs/audits/T075.10-community-legacy-code-audit-removal-plan.md`
- `docs/audits/T077.2-boucles-organization-scoped-runtime-audit-strategy.md`
- `ai/context/architecture.md`
- `ai/context/browser-tools.md`
- `ai/context/business-rules.md`
- `ai/context/development-philosophy.md`
- `ai/context/multi-tenant.md`
- `ai/context/routing-strategy.md`
- `ai/context/testing-strategy.md`
- `ai/context/ui-rules.md`
- `TODO/TASK-110-t079-0-documentation-index-agent-operating-guide.md`

Limits respected:

- documentation only
- no runtime change
- no migration
- no `app/`, `routes/`, `resources/`, `database/` or `config/` change
- no `ROADMAP.md` change
- no `@DOCS/` change
- no deletion
- no massive rename
- no massive move
- no merge

Handoff:

- ready for OPS finalize/commit/push on this branch
- after commit and push, hand off to REVIEW for documentation patch review

# Handoffs

## 2026-05-21 13:33:50 Europe/Paris

Ready for OPENAI / Codex documentation implementation on branch `TASK-110-t079-0-documentation-index-agent-operating-guide`.

OPS setup only; no documentation patch has been applied yet.

## 2026-05-21 14:00:23 Europe/Paris

OPENAI / Codex patch applied and validated as documentation-only.

Next action:

- OPS can inspect the diff, run `ai/scripts/finalize-task.sh TASK-110`, commit/push, then request REVIEW.

## 2026-05-21 14:19:36 Europe/Paris

OPENAI / Codex extended T079.0 into a controlled documentation refactor after follow-up instruction.

Scope change:

- from minimal README + banners
- to structured `docs/` hierarchy, canonical index, operational `ai/` guide, and `ai/context/*` canonical-source links

Renamed / moved paths:

| Old path | New path |
|---|---|
| `docs/02-PRODUCT_PRINCIPLES.md` | `docs/02-WORKFLOW_AND_ENGINEERING_PRINCIPLES.md` |
| `docs/06-DOMAIN_ARCHITECTURE_V2.md` | `docs/05-DOMAIN_ARCHITECTURE.md` |
| `docs/07-GLOSSARY.md` | `docs/06-GLOSSARY.md` |
| `docs/10-REFERRAL_CONTRIBUTION_FUTURE_PROOFING.md` | `docs/07-REFERRAL_CONTRIBUTION_FUTURE_PROOFING.md` |
| `docs/05-COMMUNITY_TRANSACTION_MATRIX.md` | `docs/legacy/01-COMMUNITY_TRANSACTION_MATRIX.md` |
| `docs/08-COMMUNITY_MIGRATION_STRATEGY.md` | `docs/migration/01-COMMUNITY_MIGRATION_STRATEGY.md` |
| `docs/09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md` | `docs/migration/02-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md` |
| `docs/architecture/T075.1-root-domain-tenant-resolution-strategy.md` | `docs/architecture/01-ROOT_DOMAIN_TENANT_RESOLUTION.md` |
| `docs/specs/T074.2-chatloop-center-ia-assisted-interactions.md` | `docs/specs/01-T074.2-chatloop-center-ia-assisted-interactions.md` |
| `docs/specs/T077.0-boucles-product-surface-spec.md` | `docs/specs/02-T077.0-boucles-product-surface-spec.md` |
| `docs/specs/T077.4-boucles-flux-signaux-journal-doctrine.md` | `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md` |

Refactor details:

- `docs/README.md` now indexes active root docs, architecture, migration, specs, legacy and historical audits.
- `ai/README.md` now defines `ai/` as agent operating layer and points agents back to `docs/`.
- `ai/context/*` keeps `AGENT CONTEXT ONLY` and now includes `Canonical Sources` sections.
- Existing audit files and assets were preserved.
- No broad deletion, mass rewrite, runtime change, migration, branch creation, finalization or merge.

Files intentionally not touched:

- `ROADMAP.md`
- `@DOCS/`
- `app/`
- `routes/`
- `resources/`
- `database/`
- `config/`
- `ALPHA`

Handoff:

- ready for OPS inspection/finalize after validations
- after commit and push, hand off to REVIEW for the expanded documentation refactor

## 2026-05-21 15:34:27 Europe/Paris

OPENAI / Codex applied a short pre-finalize documentation patch for three blocking contradictions.

Corrections applied:

- Corrected `/partners` vs `/partenaires` documentation:
  - `/partenaires` is documented as the canonical French public partner route.
  - `/partenaires/demande` is documented as the partner request route.
  - `/partners` is documented only as legacy redirect / compatibility.
  - `/boucles` remains documented as the canonical French route for true Boucles.
- Updated `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md`:
  - removed the obsolete authoritative projection around old T077.5/T077.6/T077.9/T078.2 labels
  - replaced it with a cautious current projection and an explicit warning that task numbering must follow the current ROADMAP / TASK files
  - kept T077.4 as doctrine only, not planning authority
- Updated `ai/agents/agents-list.md`:
  - replaced the legacy GLM/Jules/Claude-first matrix
  - documented current practical roles for ChatGPT Web / Cockpit, OPS / OpenCode, OPENAI / Codex GPT-5.5, REVIEW / OpenAI, Gemini CLI, OpenCode Go, Claude Code, GLM / Z.ai, and Jules

Scope guardrails:

- documentation only
- no runtime
- no new broad refactor
- no new git mv
- no new file outside scope
- no commit
- no finalize
- no merge
- no `app/`, `routes/`, `resources/`, `database/`, `config/`, `ROADMAP.md`, `@DOCS/`, main/PROD or ALPHA change

Handoff:

- ready for OPS finalize after final validation

## 2026-05-21 16:12:18 Europe/Paris

OPS / OpenCode finalization pass before REVIEW.

Validations completed:

- verified current branch is `TASK-110-t079-0-documentation-index-agent-operating-guide`
- inspected `git status --short --branch`
- ran `git diff --check`; passed with no output
- verified forbidden tracked paths are clean: `app/`, `routes/`, `resources/`, `database/`, `config/`, `ROADMAP.md`, `@DOCS`, `ALPHA`, `main`, `PROD`
- verified forbidden untracked paths are clean for the same path set
- ran `ai/scripts/finalize-task.sh TASK-110`; task gate passed with status `DONE`, lock `UNLOCKED`, and expected uncommitted documentation changes

Finalize script execution notes:

- script was run non-interactively because it prompts for commit / push / CI choices
- internal finalize commit/push prompts were skipped intentionally to avoid partial TASK-only commits
- full documentation patch is being committed manually after this TASK update

Scope confirmed:

- documentation only
- no runtime changes
- no migration changes
- no model changes
- no route changes
- no view changes
- no `app/`, `routes/`, `resources/`, `database/`, `config/`, `ROADMAP.md`, `@DOCS`, main/PROD or ALPHA changes
- no merge

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation
- [x] documentation scope validation

---

# Test Results

Setup validation only:

- `git pull --ff-only origin develop`: passed, already up to date
- pre-create `git status --short --branch`: clean on `develop`
- `ai/scripts/create-task.sh`: passed, created TASK-110 and branch
- `ai/scripts/check-task.sh`: failed as expected for newly opened `IN_PROGRESS` / `LOCKED` task with uncommitted TASK file

T079.0 documentation patch validation:

- Runtime feature tests: not run; documentation-only patch.
- Browser validation: not run; no UI/runtime changes.
- Responsive validation: not run; no UI/runtime changes.
- Console inspection: not run; no browser/runtime changes.
- Tenant validation: scope check performed by diff/file-path inspection; no runtime tenant code changed.
- `git diff --name-only` inspected to verify documentation-only scope.
- `git diff --check`: passed.
- `git diff --name-only app routes resources database config ROADMAP.md @DOCS`: clean output; no forbidden path changed.
- `ai/scripts/check-task.sh TASK-110`: passed with status `DONE`, lock `UNLOCKED`, and expected uncommitted documentation changes awaiting OPS finalize.
- `app/`, `routes/`, `resources/`, `database/`, `config/`, `ROADMAP.md` and `@DOCS/` remained untouched.

Extended refactor validation:

- `git diff --stat`: inspected.
- `git status --short`: inspected; changes are documentation, ai operating docs/context, and TASK only.
- `git diff --check`: passed.
- `rg` for old active documentation paths across `docs`, `ai`, and this TASK: only expected old-path mentions remain in the TASK mapping table.
- `git diff --name-only app routes resources database config ROADMAP.md @DOCS`: clean output.
- `docs/README.md`: inspected; indexes active docs, architecture, migration, specs, legacy and audits.
- `ai/README.md`: inspected; defines `ai/` as agent operating layer and points agents back to `docs/`.
- `ai/context/*.md`: inspected via `rg`; every file has `AGENT CONTEXT ONLY` and `Canonical Sources`.
- T077.4 references now point to `docs/specs/03-T077.4-boucles-flux-signaux-journal-doctrine.md`.
- `/boucles` superseded notes point to the renumbered T077.0 and T077.4 specs.
- `ai/scripts/check-task.sh TASK-110`: passed after the extended refactor.

Pre-finalize correction validation:

- `/partners` checked in `docs/`, `ai/context/`, and `ai/agents/`: remaining mentions document legacy redirect / compatibility only.
- `/partenaires` checked as canonical French partner route.
- T077.4 checked: obsolete roadmap wording removed, current projection made non-authoritative.
- `ai/agents/agents-list.md` checked: current operating roles documented.
- OPS finalization validation:
  - `git branch --show-current`: `TASK-110-t079-0-documentation-index-agent-operating-guide`
  - `git status --short --branch`: inspected before commit
  - `git diff --check`: passed
  - `git diff --name-only -- app routes resources database config ROADMAP.md @DOCS ALPHA main PROD`: clean output
  - `git ls-files --others --exclude-standard app routes resources database config ROADMAP.md @DOCS ALPHA main PROD`: clean output
  - `ai/scripts/finalize-task.sh TASK-110`: completed; internal `check-task.sh` gate passed

---

# Review Notes

T079.0 is intentionally opened as documentation workflow preparation only.

OPENAI / Codex should apply the documentation index / agent guide patch separately.

T079.0 patch is ready for OPS finalize, then REVIEW after commit/push.
