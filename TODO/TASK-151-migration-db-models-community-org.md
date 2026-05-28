---
task_id: TASK-151
title: Migration DB + Models community→organization

status: MERGED

owner: ORCHESTRATOR

contributors:
  - SUPERVISOR

branch: TASK-151-migration-community-to-org

priority: HIGH

created_at: 2026-05-28 12:30:00 Europe/Paris
updated_at: 2026-05-28 14:30:00 Europe/Paris

labels:
  - migration
  - database
  - models
  - community→org

lock:
  status: UNLOCKED
  agent: ORCHESTRATOR
  since: 2026-05-28 14:30:00 Europe/Paris

handoff: false

pr:
  status: MERGED
  url: null
---

# TASK-151 — Migration DB + Models community→organization

## Objective

Migrate the database table `communities`→`organizations`, drop 8 `community_id` columns, recreate FKs and indexes. Then clean up all Model references: `$fillable`, relations, trait, factories, and 58 test files.

## Scope

Two phases combined on one branch (exception granted by Cyril):

### P1 — Database
1. Rename `communities`→`organizations` table
2. Drop `community_id` from 8 tables (blog_posts, loops, referrals, referral_rewards, services, service_requests, transactions, users)
3. Recreate foreign keys pointing to `organizations.id`
4. Restore all dropped indexes

### P2 — Models
1. Remove `community_id` from `$fillable` in 8 models
2. Simplify `HasOrganizationId` trait
3. Add `community()`→`organization()` relation aliases for BC
4. Update 3 factories (Loop, Referral, ReferralReward)
5. Update 58 test files

---

# Planned Actions
- [x] P1: Run migration `rename_communities_to_organizations`
- [x] P1: Run migration `drop_community_id_from_tables`
- [x] P2: Edit 8 models (remove community_id from fillable)
- [x] P2: Simplify HasOrganizationId trait
- [x] P2: Update 3 factories
- [x] P2: Update 58 test files
- [x] Merge into develop

---

# Progress Log

## 2026-05-28 12:30:00 Europe/Paris
Task created. Chain lancée : P1 (DB) puis P2 (Models) sur T151.

## 2026-05-28 14:00:45 Europe/Paris
P1 commits: `b90d8fc` — communities→organizations table + drop community_id columns. 4 files, 162 insertions.

## 2026-05-28 14:07:19 Europe/Paris
P2 model commits: `56a576e` — 12 files, 23 insertions, 57 deletions. Models cleaned, factories updated.

## 2026-05-28 14:29:35 Europe/Paris
P2 test commits: `f0d8838` — 58 test files, 727 insertions, 759 deletions. Tous les community_id test refs migrés.

## 2026-05-28 14:30:00 Europe/Paris
Merge commit: `4397815` — merge(t151): community→organization migration phases 1+2 (DB + models). Mergé dans develop.

---

# Handoffs

---

# Tests
- [x] PHPUnit post-P1 : 559 pass
- [x] PHPUnit post-P2 models : 329 pass (before test fix)
- [x] PHPUnit post-P2 tests : 790 pass

---

# Test Results

| Test Run | Result |
|----------|--------|
| Post DB migration | 559 ✅ |
| Post models | 329 ✅ (partial — expected before test fix) |
| Post 58 test files | 790 ✅ |

---

# Review Notes

35 pre-existing failures remain (middleware/routing, hors scope). 0 regression from migration.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
