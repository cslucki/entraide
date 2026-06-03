---
task_id: TASK-204
title: Dashboard demo seeder — injecter données test main+cpme

status: IN_PROGRESS

owner: ORCHESTRATOR

contributors: []

branch: TASK-204-dashboard-seeder

priority: MEDIUM

created_at: 2026-06-03 10:32:32 Europe/Paris
updated_at: 2026-06-03 10:32:32 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: ORCHESTRATOR
  since: 2026-06-03 10:32:32 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer un DashboardDemoSeeder qui injecte des données de test réalistes pour les orgs "main" et "cpme", couvrant toutes les sections du superdashboard.

Contexte : après synchro PROD→LOCAL, on a des utilisateurs mais très peu de transactions, boucles, signalements, etc. Ce seeder permet de tester visuellement le superdashboard.

---

# Planned Actions

- [x] inspect architecture (fait)
- [x] inspect impacted files (fait)
- [ ] write mission brief for SUPERVISOR
- [ ] SUPERVISOR : create DashboardDemoSeeder
- [ ] SUPERVISOR : register seeder in DatabaseSeeder
- [ ] SUPERVISOR : run seeder to verify
- [ ] VERIFICATOR : review seeder
- [ ] run tests

---
# Progress Log


## 2026-06-03 10:32:32 Europe/Paris

Task created.

## 2026-06-03 10:35:00 Europe/Paris

Mission SUPERVISOR écrite dans ai-local/supervisor/report-from-orchestrator/20260603-TASK-204-DASHBOARD-SEEDER.md.
Supersivor va créer DashboardDemoSeeder avec données pour main + cpme.

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