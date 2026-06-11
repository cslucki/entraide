---
task_id: TASK-256
title: Admin AI budget alerts

status: DONE

owner: ORCH

contributors: [CODEUR, VERIFICATOR]

branch: TASK-256-admin-ai-budget-alerts

priority: MEDIUM

created_at: 2026-06-11 20:24:44 Europe/Paris
updated_at: 2026-06-11 20:24:44 Europe/Paris

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

Seuils de coût mensuel par scénario IA, avec alerte email automatique aux admins quand le seuil est dépassé. Config-driven (config/ai.php + .env), pas de UI admin. Commande `ai:check-budgets` à scheduler.

## Scope

| Action | Fichier |
|--------|---------|
| CREATE | `app/Notifications/AiBudgetExceeded.php` |
| CREATE | `app/Console/Commands/CheckAiBudgets.php` |
| CREATE | `tests/Feature/Console/CheckAiBudgetsTest.php` |
| MODIFY | `config/ai.php` |
| MODIFY | `.env.example` |
| MODIFY | `routes/console.php` |

## Règles métier
- Seuils dans config/ai.php (env-driven)
- Commande calcule SUM(cost_usd) du mois en cours par scenario_id
- Alerte mail si dépassement
- Anti-spam : cache Laravel (1 mois) pour un seul envoi/scénario/mois
- Safe à scheduler hourly

## HORS scope
- UI admin, sidebar, CRUD, dashboard
- Slack/notifications in-app
- Seuils par provider

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-11 20:24:44 Europe/Paris

Task created.

Owner: CODEUR
Branch: TASK-256-admin-ai-budget-alerts
Status: IN_PROGRESS

## 2026-06-11 20:27:00 Europe/Paris

CODEUR completed implementation.

Fichiers créés (4) :
- app/Notifications/AiBudgetExceeded.php
- resources/views/emails/ai_budget_exceeded.blade.php
- app/Console/Commands/CheckAiBudgets.php
- tests/Feature/Console/CheckAiBudgetsTest.php

Fichiers modifiés (3) :
- config/ai.php — section budget_alerts
- .env.example — vars AI_BUDGET_*
- routes/console.php — commande enregistrée

Tests: 9/9 ✅ (25 assertions)
Regression: 250/250 ✅

Lock transferred to VERIFICATOR.

## 2026-06-11 20:30:00 Europe/Paris

VERIFICATOR: ✅ OK. 9/9 tests. Regression 250/250.

Transfer to ORCH for merge.

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

CheckAiBudgetsTest: 9/9 ✅ (25 assertions)
Admin regression: 250/250 ✅
VERIFICATOR: ✅ OK — scope conforme, tests verts, zéro régression

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