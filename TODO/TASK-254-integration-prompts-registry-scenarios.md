---
task_id: TASK-254
title: Integration prompts registry scenarios

status: DONE

owner: CODEUR

contributors: []

branch: TASK-254-integration-prompts-registry-scenarios

priority: MEDIUM

created_at: 2026-06-11 19:56:59 Europe/Paris
updated_at: 2026-06-11 19:56:59 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 19:56:59 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Remplacer les prompts hardcodés dans SupervisionContentScenario et ClarifyHelpRequestScenario par AdminAiPrompt::active() depuis la DB. Fallback sur constante hardcodée si aucun prompt en DB.

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-11 19:56:59 Europe/Paris

Task created.

Owner:
CODEUR

Branch:
TASK-254-integration-prompts-registry-scenarios

Status:
IN_PROGRESS

## 2026-06-11 19:58:00 Europe/Paris

CODEUR implementation complete.

Modified:
- app/Services/Ai/Scenarios/SupervisionContentScenario.php — DB lookup + fallback
- app/Services/Ai/Scenarios/ClarifyHelpRequestScenario.php — DB lookup + fallback

Created:
- tests/Feature/Admin/AdminAiPromptIntegrationTest.php

Tests:
- AdminAiPromptIntegrationTest: 5/5 passed (9 assertions)
- AdminAiSupervisionTest: 48/48 passed (187 assertions) — regression OK

Status: awaiting VERIFICATOR

## 2026-06-11 20:05:00 Europe/Paris

VERIFICATOR: ✅ OK. 66/66 tests passés. Aucun écart. Périmètre respecté.

## 2026-06-11 20:05:30 Europe/Paris

ORCH: check, finalize, merge.

# Handoffs

# Tests

- [x] feature tests — AdminAiPromptIntegrationTest 5/5, AdminAiSupervisionTest 48/48, AdminAiInteractionTest 13/13
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`