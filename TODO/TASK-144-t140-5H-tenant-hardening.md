---
task_id: TASK-144-t140-5H
title: T140.5H Tenant Boundary Hardening

status: IN_PROGRESS

owner: OpenCode
branch: TASK-144-t140-5H-tenant-hardening
created_at: 2026-05-25 14:41:31 Europe/Paris

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5H — Tenant Boundary Hardening

## Objectif
Hardening des 3 failles de tenant boundary identifiées par le Final Review (T140.5G) :
1. RewardDispatcher cross-org referrals
2. Loop implicit model binding enumeration
3. WebSocket channel organization validation

## Périmètre autorisé
- `app/Services/RewardDispatcher.php` — patch cross-org referrals
- `app/Http/Controllers/LoopController.php` — implicit binding enumeration
- `routes/channels.php` — WebSocket channel org validation
- Tests ciblés obligatoires

## Périmètre interdit
- Refonte architecture
- Changement doctrine
- Modifications tooling
- Élargissement roadmap
- Database/*, migrations/*

## Ordre
1. RewardDispatcher cross-org referrals
2. Loop implicit model binding enumeration
3. WebSocket channel organization validation

---

# Modified Files

<!-- à remplir après implémentation -->

# Tests

<!-- à remplir après exécution -->
