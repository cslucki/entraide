---
task_id: TASK-146
title: Greenfield Playwright Business Interaction Suite

status: IN_PROGRESS

owner: PROJECT_SUPERVISOR

contributors:
  - PLAYWRIGHT_QA

branch: TASK-146-greenfield-playwright-business-interaction-suite

priority: HIGH

created_at: 2026-05-25 23:07:39 Europe/Paris
updated_at: 2026-05-25 23:07:39 Europe/Paris

labels:
  - playwright
  - qa
  - greenfield
  - business-flows

lock:
  status: LOCKED
  agent: PROJECT_SUPERVISOR
  since: 2026-05-25 23:07:39 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Create a clean, documented, reusable Playwright test suite for critical BouclePro business interaction flows. Greenfield approach: no logical dependency on legacy community-transactions tests. 20 P0 flows covering auth, services, requests, transactions, blog, loops, and admin dashboards — using .env QA accounts exclusively.

# Planned Actions

- [ ] RUN0 — create task / branch / cockpit
- [ ] RUN1 — audit Playwright existing (read-only, no reuse)
- [ ] RUN2 — validate QA accounts .env + seed DB + Default Organization
- [ ] RUN3 — create helpers: auth, data naming, screenshots, console errors
- [ ] RUN4 — micro-service create/show/edit with qa-member1
- [ ] RUN5 — help request create/show
- [ ] RUN6 — transaction member 1 ↔ member 2
- [ ] RUN7 — blog create/show/admin
- [ ] RUN8 — loop create/show
- [ ] RUN9 — admin dashboard pages
- [ ] RUN10 — full suite + coverage report + industrial validation
- [ ] Final gates: route:cache, optimize, build, test, Playwright suite

---

# Progress Log

## 2026-05-25 23:07:39 Europe/Paris

RUN0: Task created, branch checked out, cockpit files created.
- Branch: TASK-146-greenfield-playwright-business-interaction-suite
- 6 cockpit files: MASTER, RUN-LOG, INTERACTION-MATRIX, PLAYWRIGHT-COVERAGE, FINAL-REPORT
- P0 defined: 20 flows across 11 interaction categories

# Handoffs

# Tests

- [ ] RUN1 — audit existing Playwright
- [ ] RUN2 — seed DB + account validation
- [ ] RUN3 — helper tests (auth, naming)
- [ ] RUN4 — service create/show/edit
- [ ] RUN5 — help request
- [ ] RUN6 — transaction
- [ ] RUN7 — blog
- [ ] RUN8 — loop
- [ ] RUN9 — admin dashboard
- [ ] RUN10 — full suite run + gates

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
