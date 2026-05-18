---
task_id: ALPHA-SETUP-01
title: Alpha1 local worktree setup from production main

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: ALPHA-SETUP-01-alpha1-setup

priority: HIGH

created_at: 2026-05-18 11:33:02 Europe/Paris
updated_at: 2026-05-18 11:37:30 Europe/Paris

labels:
  - alpha
  - ops
  - worktree

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-18 11:33:02 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Prepare the isolated local alpha1 worktree from the exact production deployment base:
`main` commit `b392a134e85a26a5018d2c371aeaebe20802bb63`.

This task is setup-only. No runtime patch, migration, Apache configuration, PostgreSQL production credential copy, or T074/T075/T076 backport is allowed.

---

# Guardrails

- ALPHA base: `main` at `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- Forbidden base: `develop`.
- Forbidden base: current T076 work branch.
- Forbidden: modifying `main` / production.
- Forbidden: backporting T074/T075/T076.
- Forbidden: runtime patch during ALPHA-SETUP-01.
- Forbidden: storing production database secrets in this repository or TASK file.
- Local alpha database name: `bouclepro_alpha1`.
- Local alpha URL: `https://alpha1.test.laravel`.

---

# Planned Actions

- [x] confirm Cyril GO decision and production SHA
- [x] inspect official task script behavior before use
- [x] create dedicated branch from production SHA
- [x] create alpha1 worktree at `/home/cyril/claude-code/sites/alpha1.test.laravel`
- [x] create local alpha `.env` from `.env.pgsql`
- [x] verify branch, base SHA, worktree, and local alpha env keys
- [ ] prepare PostgreSQL local alpha database configuration in a later step
- [ ] prepare Apache alpha vhost configuration in a later step

---

# Progress Log

## 2026-05-18 11:33:02 Europe/Paris

Task created manually instead of using `ai/scripts/create-task.sh` because the official script creates branches from the current checkout. Current checkout was not the approved alpha base, so manual branch/worktree creation was required to preserve Cyril's base constraint.

Production base confirmed by Cyril:

- Branch: `main`
- Commit: `b392a134e85a26a5018d2c371aeaebe20802bb63`
- Short SHA: `b392a13`
- Deployment message: `chore: release T073 pre-T074 to main`

Created branch/worktree:

- Branch: `ALPHA-SETUP-01-alpha1-setup`
- Worktree: `/home/cyril/claude-code/sites/alpha1.test.laravel`
- Base: `b392a134e85a26a5018d2c371aeaebe20802bb63`

Production database credential file was only inspected for allowed non-secret keys. No production secret was displayed, copied, or stored.

## 2026-05-18 11:37:00 Europe/Paris

Created local alpha `.env` inside the alpha worktree from `.env.pgsql` with these alpha-specific non-secret settings verified:

- `APP_URL=https://alpha1.test.laravel`
- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=bouclepro_alpha1`
- `DB_USERNAME=bouclepro`

Verified `.env` is ignored by git. No production database password or credential file was copied into the repository.

Verified branch/worktree state:

- Alpha worktree path: `/home/cyril/claude-code/sites/alpha1.test.laravel`
- Alpha branch: `ALPHA-SETUP-01-alpha1-setup`
- Alpha HEAD: `b392a134e85a26a5018d2c371aeaebe20802bb63`
- Alpha HEAD message: `chore: release T073 pre-T074 to main`
- Original worktree remains on `develop` and was not modified.

## 2026-05-18 11:37:30 Europe/Paris

Initial checkpoint before commit/push:

- Current worktree: `/home/cyril/claude-code/sites/alpha1.test.laravel`.
- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Current base before TASK commit: `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- Laravel Cloud production decision recorded: latest successful production deployment is short SHA `b392a13` from `main` with message `chore: release T073 pre-T074 to main`.
- Git status before commit contains only `TODO/ALPHA-SETUP-01-alpha1-setup.md` as an untracked file.
- Alpha `.env` was created locally from `.env.pgsql`, remains gitignored, and is not part of the commit.
- No runtime files were modified.
- No migration was run.
- No PostgreSQL alpha database was created or imported in this micro-step.
- No Apache configuration was modified in this micro-step.

Next OPS step: prepare PostgreSQL local alpha database (`bouclepro_alpha1`) and Apache alpha vhost configuration, still without production import until explicitly authorized.

# Handoffs

None.

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Setup verification completed without runtime patching:

- `git rev-parse HEAD` in alpha worktree returned `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- `git log -1 --format='%h %s'` returned `b392a13 chore: release T073 pre-T074 to main`.
- `git worktree list` shows alpha worktree at `/home/cyril/claude-code/sites/alpha1.test.laravel` on branch `ALPHA-SETUP-01-alpha1-setup`.
- Non-secret local `.env` keys confirm alpha URL and alpha database name.
- `.env` appears as ignored in git status.

---

# Review Notes

ALPHA-SETUP-01 is an OPS/setup task only. Runtime behavior remains unchanged.
