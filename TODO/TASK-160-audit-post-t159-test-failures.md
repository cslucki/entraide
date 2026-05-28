---
task_id: TASK-160
status: MERGED
owner: OpenCode
branch: TASK-160-audit-post-t159-test-failures
lock:
  status: UNLOCKED
  agent: none
  since: null
---

# TASK-160 — Audit post-T159 test suite failures

## Status
DONE

## Résultat
818 ✅ / 6 ❌ / 11 ⏭️ (plus de segfault — suite complète en 22s)

## 6 échecs classifiés
- **3 pre-existing** (tests legacy non mis à jour: OrganizationCompatibility + T1392)
- **3 regressions** (Organization factory, RewardDispatcher T157, HasOrganizationId allowlist T1403)

## Recommandation
Corrigible en une seule tâche (T161).

## Fichiers modifiés
- `TODO/TASK-160-audit-post-t159-test-failures.md`
- `.ai-local/supervisor/report-to-orchestrator/20260528-TASK-160-AUDIT-TESTS.md`
