---
task_id: TASK-258
title: Help request AI preview UI + sidebar persistence + AI settings

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-258-help-request-ai-preview-ui-sidebar-persistence-ai-settings

priority: MEDIUM

created_at: 2026-06-11 21:03:37 Europe/Paris
updated_at: 2026-06-11 21:20:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-11 21:20:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Describe the objective.

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-11 21:03:37 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-258-help-request-ai-preview-ui-sidebar-persistence-ai-settings

Status:
IN_PROGRESS

## 2026-06-11 21:20:00 Europe/Paris

Implementation complete.

3 blocs:
1. Sidebar persistence — localStorage for 5 groups (Email/Échanges/Organisations/Outils/IA)
2. AI Settings page — AiConfig model + AdminAiConfigController + view + routes + sidebar
3. Help request AI preview UI — confidence badge, needs_human_review, sensitive data banners

Files created (7):
- database/migrations/2026_06_11_150010_create_ai_configs_table.php
- app/Models/AiConfig.php
- app/Http/Controllers/Admin/AdminAiConfigController.php
- resources/views/admin/ai-config/index.blade.php
- tests/Feature/Admin/AdminAiConfigTest.php

Files modified (5):
- resources/views/layouts/admin.blade.php
- resources/views/loops/show.blade.php
- routes/web.php
- app/Services/Ai/SupervisionProviderResolver.php
- app/Providers/AppServiceProvider.php

Tests: AdminAiConfigTest 9/9 (19 assertions), Admin/* 259/259 (0 regression), LoopHelpRequest 19/19.

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation (out of scope)
- [x] responsive validation (sidebar)
- [ ] console inspection
- [ ] tenant validation (not applicable)

---

# Test Results

AdminAiConfigTest: 9/9 passed (19 assertions)
Admin/*: 259/259 passed (789 assertions) — 0 regression
LoopHelpRequestTest: 19/19 passed

---

# Review Notes

Settings table (settings) was dropped in a previous migration (2026_06_02_000040). Created new ai_configs table instead.
AppServiceProvider::boot() overrides config('ai.default_provider') and config('ai.default_model') from AiConfig DB.
SupervisionProviderResolver::defaultProvider() checks config override first, then falls back to existing logic.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`