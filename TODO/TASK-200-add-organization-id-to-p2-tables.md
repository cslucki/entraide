---
task_id: TASK-200
title: Add organization_id to P2 tables (categories, skills, tags, badges, point_guidelines, email_templates, email_logs + 5 pivot tables)

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-200-p2-organization-id

priority: MEDIUM

created_at: 2026-06-02 14:15:00 Europe/Paris
updated_at: 2026-06-02 14:15:00 Europe/Paris

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

Add `organization_id` (nullable UUID, FK → organizations, nullOnDelete) to 7 entity tables + 5 pivot tables. Update 7 models with fillable + organization() relation. No trait, no scope.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] create migration
- [x] update 7 models
- [x] fix BlogController ambiguous org_id
- [x] run tests
- [x] commit + push + report

---

# Progress Log

## 2026-06-02 14:15:00 Europe/Paris

Task created.

Owner: SUPERVISOR

Branch: TASK-200-p2-organization-id

Status: IN_PROGRESS

## 2026-06-02 14:20:00 Europe/Paris

Migration created, 7 models updated. BlogController fixed for ambiguous `organization_id` in `withCount` subquery (blog_post_category now has org_id). Tests: 811 passed, 14 failed (Resend API quota), 11 skipped — baseline clean.

---

# Handoffs

# Tests

- [x] feature tests

---

# Test Results

811 passed, 14 failed (pre-existing Resend API TransportException), 11 skipped. No regressions.

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
