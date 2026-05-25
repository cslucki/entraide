---
task_id: TASK-144-t140-5D
title: T140.5D LoopController → organization_id-first

status: IN_PROGRESS

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5D-controllers-metier

priority: MEDIUM

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - organization
  - loop
  - controller

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5D — LoopController → organization_id-first

## Objectif

Migrer les guards tenant dans LoopController de `community_id` vers `organization_id` (fallback conservé).

## Périmètre autorisé

- `app/Http/Controllers/LoopController.php` — 10 community_id refs
- Tests strictement nécessaires associés
- `TODO/TASK-144-t140-5D-controllers-metier.md`
- `docs/audits/T140.5D-controllers-metier.md`

## Interdit

- T140.5B/T140.5C services (already merged)
- admin, auth, Livewire
- database/*, migrations/*
- modèles, policies, VERSION
- T140.5E

---

# Pre-flight Summary

## LoopController.php — 10 refs

| Line | Code | Pattern |
|------|------|---------|
| 46 | `$user->community_id !== $community->id` | Guard (assertUserBelongsToCommunity) → `($user->organization_id ?? $user->community_id) !== $community->id` |
| 62 | `return $user->community_id` | Read (resolveCommunityId) → `return $user->organization_id ?? $user->community_id` |
| 77 | `->where('community_id', $communityId)` | Query filter (index) → `->where('organization_id', $communityId)` |
| 129 | `$loop->community_id !== $community->id` | Guard (show) → `$loop->organization_id !== $community->id` |
| 161 | `$loop->community_id !== $community->id` | Guard (join) → `$loop->organization_id !== $community->id` |
| 200 | `$loop->community_id !== $community->id` | Guard (leave) → `$loop->organization_id !== $community->id` |
| 232 | `$loop->community_id !== $community->id` | Guard (analyzeHelpIntention) → `$loop->organization_id !== $community->id` |
| 268 | `$loop->community_id !== $community->id` | Guard (publishHelpRequest) → `$loop->organization_id !== $community->id` |
| 326 | `$loop->community_id !== $community->id` | Guard (addMember) → `$loop->organization_id !== $community->id` |
| 362 | `$loop->community_id !== $community->id` | Guard (storeMessage) → `$loop->organization_id !== $community->id` |

---

# Modified Files

<!-- à remplir après implémentation -->

# Tests

<!-- à remplir après exécution -->
