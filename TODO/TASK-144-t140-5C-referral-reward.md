---
task_id: TASK-144-t140-5C
title: T140.5C ReferralService + RewardDispatcher → organization_id-first

status: IN_PROGRESS

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5C-referral-reward

priority: MEDIUM

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - organization
  - referral
  - rewards

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5C — ReferralService + RewardDispatcher

## Objectif

Migrer les guards tenant dans ReferralService et RewardDispatcher de `community_id` vers `organization_id` (fallback conservé).

## Périmètre autorisé

- `app/Services/ReferralService.php` — 6 community_id refs
- `app/Services/RewardDispatcher.php` — 7 community_id refs
- `tests/Feature/RewardDispatcherTest.php` — assertions + factory setup
- `tests/Feature/ReferralServiceTest.php` — vérification (déjà org-first)
- `TODO/TASK-144-t140-5C-referral-reward.md`

## Interdit

- `app/Models/PointLedger.php`
- `app/Http/Controllers/Admin/AdminReferralController.php`
- `app/Http/Controllers/LoopController.php`
- database/*, migrations/*

---

# Pre-flight Summary

## ReferralService.php — 6 refs

| Line | Code | Pattern |
|------|------|---------|
| 23 | `$communityId = $organizationId ?? $referred->community_id` | Read → `$orgId = $organizationId ?? $referred->organization_id ?? $referred->community_id` |
| 29 | `$referrer->community_id !== $communityId` | Guard → `$referrer->organization_id !== $orgId` |
| 33 | `$referred->community_id !== $communityId` | Guard → `$referred->organization_id !== $orgId` |
| 37 | `where('community_id', $communityId)` | Query → `where('organization_id', $orgId)` |
| 46 | `where('community_id', $communityId)` | Query → `where('organization_id', $orgId)` |
| 57 | `where('community_id', $communityId)` | Query → `where('organization_id', $orgId)` |

## RewardDispatcher.php — 7 refs

| Line | Code | Pattern |
|------|------|---------|
| 21 | `$communityId = $event->organizationId ?? $event->referrer->community_id` | Read → `$orgId = $event->organizationId ?? $event->referrer->organization_id ?? $event->referrer->community_id` |
| 27 | `$event->referrer->community_id !== $communityId` | Guard → `$event->referrer->organization_id !== $orgId` |
| 31 | `$event->referred->community_id !== $communityId` | Guard → `$event->referred->organization_id !== $orgId` |
| 35 | `where('community_id', $communityId)` | Query → `where('organization_id', $orgId)` |
| 46 | `'community_id' => $communityId` (write) | Write → `'organization_id' => $orgId` |
| 101 | `'community_id' => $communityId` (write) | Write → `'organization_id' => $orgId` |
| 133 | `'community_id' => $referral->community_id` (write) | Write → `'organization_id' => $referral->organization_id` |

## RewardDispatcherTest — 6 assertion + factory updates

- 6 assertions: `$obj->community_id` → `$obj->organization_id`
- Factory setup: `['community_id' => $this->org->id]` → `['organization_id' => $this->org->id]`

## Risques

- PointLedger untouched ✅
- Referral + ReferralReward models have HasOrganizationId ✅
- DB writes use organization_id, HasOrganizationId syncs community_id ✅

---

# Modified Files

<!-- à remplir après implémentation -->

# Tests

<!-- à remplir après exécution -->
