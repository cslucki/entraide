---
task_id: TASK-050
title: Example Task

status: TODO

owner: null

contributors: []

branch: null

# validation_level: MICRO_UX | STANDARD | SENSITIVE | NO_APP_CI
# Definitions courtes -> AGENTS.md, section "Validation Levels".
validation_level: null

priority: MEDIUM

created_at: null
updated_at: null

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
- Run `ai/scripts/bump-version.sh` on the task branch BEFORE `finalize-task.sh`
- `merge-task.sh` verifies VERSION format but does NOT bump it
- Footer always displays `config('app.version')`
