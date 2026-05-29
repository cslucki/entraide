---
task_id: TASK-169
title: Refactor: cleanup CommunityFactory

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-169-refactor-cleanup-communityfactory

priority: MEDIUM

created_at: 2026-05-29 21:48:20 Europe/Paris
updated_at: 2026-05-29 21:48:20 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Cleanup CommunityFactory: merge into OrganizationFactory and delete CommunityFactory.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log


## 2026-05-29 21:48:20 Europe/Paris

Task created.

## 2026-05-29 21:50:00 Europe/Paris

- Analyzed CommunityFactory.php: $model = Organization::class, 4 methods (definition, inactive, withHero)
- Found OrganizationFactory extends CommunityFactory (empty child)
- No direct CommunityFactory references in tests (0 hits for Community::factory())
- Merged CommunityFactory content into OrganizationFactory, deleted CommunityFactory.php
- Full test suite: 825 passed, 11 skipped — zero regression

Owner:
SUPERVISOR

Branch:
TASK-169-refactor-cleanup-communityfactory

Status:
IN_PROGRESS

# Handoffs

# Tests

- [ ] feature tests
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