---
task_id: TASK-178
title: Cleanup dead code legacy: ResolveOrganization fallback + BelongsToTenantScope rename

status: DONE

owner: SUPERVISOR

contributors: []

branch: TASK-178-cleanup-dead-code-legacy-resolveorganization-fallback-belongstotenantscope-rename

priority: MEDIUM

created_at: 2026-05-30 23:48:37 Europe/Paris
updated_at: 2026-05-30 23:48:37 Europe/Paris

labels: []

lock:
  status: UNLOCKED

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Supprimer le dead fallback `$request->route('community') ??` dans ResolveOrganization.php et renommer BelongsToTenantScope → BelongsToOrganizationScope (classe + fichier + toutes les références).

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-05-30 23:48:37 Europe/Paris

Task created.

## 2026-05-31 00:05 Europe/Paris

SUPERVISOR exécute RUN-002/TASK-178.

**Commit 1 (ddb4db7)**: Suppression du dead fallback `$request->route('community') ??` dans `app/Http/Middleware/ResolveOrganization.php`.
- Mise à jour des routes de test inline qui utilisaient `{community}` → `{organization}`:
  - OrganizationCompatibilityTest (6 routes)
  - OrganizationRouteCompatibilityTest (2 routes: rename + simplify test)
  - T1403CurrentCommunityFallbackGatesTest (1 route)
- Tests : T1392LegacyCharacterizationTest 🟢, OrganizationCompatibilityTest 🟢, full suite : 824 passed, 11 skipped, 0 failures 🟢

**Commit 2 (84de8e9)**: Renommage `BelongsToTenantScope` → `BelongsToOrganizationScope`.
- Création du fichier `app/Models/Scopes/BelongsToOrganizationScope.php`
- Suppression de `app/Models/Scopes/BelongsToTenantScope.php`
- Renommage `tests/Feature/BelongsToTenantScopeTest.php` → `tests/Feature/BelongsToOrganizationScopeTest.php`
- Mise à jour des références dans:
  - Models: Service.php, ServiceRequest.php, Referral.php, ReferralReward.php, Transaction.php
  - Controllers: HomeController.php, TransactionController.php, AdminReferralController.php
  - Tests: BelongsToOrganizationScopeTest.php, ReferralTest.php, ServiceControllerTest.php, T126DesyncCommunityOrganizationIdTest.php, T1392KnownRisksTest.php, T1392LegacyCharacterizationTest.php
- `composer dump-autoload` pour régénérer l'autoload
- Full suite : 824 passed, 11 skipped, 0 failures 🟢

# Handoffs

Handoff to ORCHESTRATOR for finalize+merge.

# Tests

- [x] feature tests (full suite: 824 passed, 11 skipped, 0 failures)

---

# Test Results

824 passed, 11 skipped, 0 failures. (identical à baseline)

---

# Review Notes

1. Le sed initial pour Commit 2 n'a pas fonctionné sur tous les fichiers (problème de chemins relatifs vs absolus). Corrigé manuellement.
2. Le fichier `app/Models/Scopes/BelongsToTenantScope.php` a été supprimé puis recréé, mais le composer dump-autoload a échoué à cause des références restantes dans plusieurs fichiers. Corrigé en relançant sed sur tous les fichiers manquants.
3. Le TASK-178 file a été supprimé accidentellement lors du `git add -A` pour Commit 2. Restauré depuis le commit précédent.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`