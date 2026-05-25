---
task_id: TASK-144-t140-5B
title: T140.5B LoopService + LoopMessageService → organization_id-first

status: DONE

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5B-loop-services

priority: MEDIUM

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25

labels:
  - organization
  - loop
  - services

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5B — LoopService + LoopMessageService

## Objectif

Migrer les guards tenant dans LoopService et LoopMessageService de `community_id` vers `organization_id` (fallback community_id conservé).

## Périmètre autorisé

- `app/Services/LoopService.php` — 8 community_id refs à migrer
- `app/Services/LoopMessageService.php` — 1 community_id ref à migrer
- Tests strictement nécessaires (mise à jour des tests existants + nouveau test dédié)
- `TODO/TASK-144-t140-5B-loop-services.md`
- `docs/audits/T140.5B-loop-services.md`
- `TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*`

## Interdit

- `app/Http/Controllers/LoopController.php` (modification — lecture autorisée)
- controllers web, Livewire, referrals/rewards
- auth, admin, database/*, migrations/*
- modèles, policies métier, VERSION
- T140.5C/D/E

---

# Pre-flight Summary

## LoopService.php — 8 community_id refs

| Line | Code | Pattern |
|------|------|---------|
| 16 | `$communityId = $user->community_id;` | Read source → `$user->organization_id ?? $user->community_id` |
| 25 | `'community_id' => $communityId` | Write to model → `'organization_id' => $communityId` (HasOrganizationId syncs community_id) |
| 41 | `$loop->community_id !== $user->community_id` | Cross-tenant guard (addMember) → `$loop->organization_id !== $orgId` |
| 64 | `$loop->community_id !== $user->community_id` | Cross-tenant guard (getEligibleReferrals) → `$loop->organization_id !== $orgId` |
| 72 | `->where('community_id', $loop->community_id)` | Query filter → `->where('organization_id', $loop->organization_id)` |
| 74 | `$q->where('community_id', $loop->community_id)` | Subquery filter → `->where('organization_id', $loop->organization_id)` |
| 87 | `$referral->community_id !== $loop->community_id` | Cross-tenant guard (addReferralToLoop) → `$referral->organization_id !== $loop->organization_id` |
| 106 | `Loop::where('community_id', $communityId)` | Slug uniqueness → `Loop::where('organization_id', $communityId)` |

## LoopMessageService.php — 1 community_id ref

| Line | Code | Pattern |
|------|------|---------|
| 83 | `$loop->community_id !== $sender->community_id` | Cross-tenant guard (assertCanSend) → `$loop->organization_id !== $orgId` |

## Dépendances

- Loop model already uses `HasOrganizationId` (auto-syncs org_id)
- LoopMember: no tenant columns (relational)
- LoopMessage: no tenant columns (relational)
- `routes/channels.php` line 25-26 already uses `$loop->organization_id` (T140.5A)

## Risques

- `HasOrganizationId` auto-sync peut masquer des bugs (les deux colonnes sont toujours égales en test)
- Tests existants (~77) utilisent `community_id` — doivent être mis à jour

---

# Modified Files

- `app/Services/LoopService.php` — 8 community_id refs → organization_id-first
- `app/Services/LoopMessageService.php` — 1 community_id ref → organization_id-first
- `tests/Feature/LoopCreationTest.php` — error message assertion updated
- `tests/Feature/LoopMessageTest.php` — error message assertion updated
- `tests/Feature/LoopMemberInvariantTest.php` — 3 error message assertions updated

# Tests

**Sous-agent TEST_WORKER** — 221 passed, 11 skipped (known risks), 0 failed

| Suite | Result |
|-------|--------|
| LoopCreationTest | 10/10 ✅ |
| LoopMemberInvariantTest | 22/22 ✅ |
| LoopMessageTest | 21/21 ✅ |
| LoopHelpRequestTest | 19/19 ✅ |
| LoopActivityTrackingTest | 10/10 ✅ |
| LoopModelTest | 19/19 ✅ |
| LoopVisibilityMembershipTest | 7/7 ✅ |
| T07411RoutesTenantSafetyTest | 21/21 ✅ |
| T1392LegacyCharacterizationTest | 28/28 ✅ |
| T1392RouteSmokeGatesTest | 35/35 ✅ |
| T1405ARuntimeOrganizationIdTest | 15/15 ✅ |
| T1392KnownRisksTest | 2/2 + 11 skipped (documented) |
| AdminLoopsTest | 8/8 ✅ |
| AdminMessagesTest | 5/5 ✅ |

**Sous-agent REVIEW_SUPERVISOR : GO** ✅
**Sous-agent STEP_GLOBAL_REVIEWER : GO** ✅
