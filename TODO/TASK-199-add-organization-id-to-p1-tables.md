---
task_id: TASK-199
title: Add organization_id to remaining P1 tables (blog_comments, point_ledger, favorites, likes, service_images, request_attachments)

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-199-org-id-p1-tables

priority: MEDIUM

created_at: 2026-06-02 14:10:00 Europe/Paris
updated_at: 2026-06-02 14:10:00 Europe/Paris

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

Add `organization_id` column to 6 P1 tables (blog_comments, point_ledger, favorites, likes, service_images, request_attachments) and update corresponding models.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests

---

# Progress Log

## 2026-06-02 14:10:00 Europe/Paris

Task created.

Owner:
SUPERVISOR

Branch:
TASK-199-org-id-p1-tables

Status:
DONE

---

# Handoffs

# Tests

- [x] feature tests (811 passed, 14 pre-existing Resend API failures, 11 skipped)

---

# Test Results

811 passed, 14 failed (all TransportException from Resend API quota), 11 skipped. Same results before and after — no regressions.

---

# Review Notes

Columns already existed in PostgreSQL DB (from previous schema state). Only model updates were needed.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
