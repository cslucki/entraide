---
task_id: TASK-179
title: tests PHPUnit isolation tenant
owner: SUPERVISOR
branch: TASK-179-tests-phpunit-isolation-tenant
status: IN_PROGRESS

contributors:
  - ORCHESTRATOR
  - VERIFICATOR

lock:
  status: LOCKED
  agent: SUPERVISOR
  since: 2026-05-31 00:00:00 Europe/Paris

---

## Objectif

Valider l'isolation tenant dans les tests PHPUnit après la migration Community → Organization.

## Contexte

Après TASK-177 (rename `$community` → `$organization` dans tests) et TASK-178 (dead code cleanup), Phase B : tests d'isolation tenant.

## Spécifications

### Portée

1. **Audit des tests PHPUnit concernés** :
   - Identifier tous les tests qui manipulent plusieurs organizations
   - Identifier les tests qui créent/switch entre tenants

2. **Cas d'isolation à valider** :
   - `T07411RoutesTenantSafetyTest.php` — validation sécurité routes
   - `T1392LegacyCharacterizationTest.php` — fallback behavior
   - `T1392RouteSmokeGatesTest.php` — smoke test routes
   - `T1403CurrentCommunityFallbackGatesTest.php` — fallback gates

3. **Tests d'isolation nouveaux** (si nécessaire) :
   - Créer des tests qui démontrent l'isolation :
     - User A dans Org X ne voit pas data de User B dans Org Y
     - Query scopes respectent organization_id
     - Policy scoping fonctionne

### Contraintes

- **Aucun refactor massif** — tests d'observation seulement
- **Si bug détecté** → créer TASK séparée (ne pas mixer audit + fix)
- **Si tests passent déjà** → documenter et clore

## Progress Log

2026-05-31 00:00:00 Europe/Paris
- TASK créée par ORCHESTRATOR
- Branch `TASK-179-tests-phpunit-isolation-tenant` créée
- Attente ordre SUPERVISOR

## Modified Files

Aucun

## Tests

- Audit planifié
- Tests d'isolation à exécuter

## Blockers

Aucun

## Review Notes

À venir

## Handoff

En attente ordre SUPERVISOR