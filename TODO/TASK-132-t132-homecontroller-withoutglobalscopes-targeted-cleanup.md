---
task_id: TASK-132
title: t132-homecontroller-withoutglobalscopes-targeted-cleanup

status: DONE

owner: CODEX

contributors: []

branch: TASK-132-t132-homecontroller-withoutglobalscopes-targeted-cleanup

priority: LOW

created_at: 2026-05-24 08:22:19 Europe/Paris
updated_at: 2026-05-24 09:10:00 Europe/Paris

labels:
  - cleanup
  - tenant
  - withoutGlobalScope
  - HomeController

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-24 09:10:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Remplacer les usages `withoutGlobalScopes()` globaux dans HomeController par la forme ciblée
`withoutGlobalScope(BelongsToTenantScope::class)`, sans modifier le comportement fonctionnel.

Contexte : T129 a documenté ces 4 surfaces comme DETTE MOYEN dans l'allowlist withoutGlobalScope.
Toutes sont couvertes par T0754DashboardMembersExchangesTenantSafetyTest (4 tests verts).

---

# Hors scope

- pas de modification BelongsToTenantScope
- pas de migration community_id → organization_id
- pas de renommage Community
- pas de correction Explorer/Profile
- pas de cleanup branches
- pas de footer version
- pas de correction SQLite batch
- pas de refactor HomeController large
- pas de main / PROD

---

# Planned Actions

## Phase A — Lecture et préparation

- [x] lire HomeController.php (4 usages withoutGlobalScopes() : lignes 46, 47, 49, 76)
- [x] lire T0754DashboardMembersExchangesTenantSafetyTest.php (4 tests, couvrent membres + échanges)
- [x] lire withoutGlobalScope-allowlist.md (HomeController classé DETTE MOYEN)
- [x] lire T128-tenant-id-source-of-truth-strategy.md (étape 3 roadmap : nettoyer HomeController)

## Phase B — Patch ciblé

- [x] remplacer withoutGlobalScopes() → withoutGlobalScope(BelongsToTenantScope::class) dans membres() (×3)
- [x] remplacer withoutGlobalScopes() → withoutGlobalScope(BelongsToTenantScope::class) dans exchanges() (×1)
- [x] ajouter use App\Models\Scopes\BelongsToTenantScope (Pint a réordonné les imports)

## Phase C — Tests

- [x] T0754DashboardMembersExchangesTenantSafetyTest : 4/4 verts ✓
- [x] batch complet SQLite run 1 : 723 passed + 2 ordre-dépendants (LoopCreation, LoopActivity) — non liés au patch
- [x] batch complet SQLite run 2 : 725/725 ✓ — intermittence confirmée

## Phase D — Allowlist et finalisation

- [x] mettre à jour docs/architecture/withoutGlobalScope-allowlist.md (HomeController : DETTE MOYEN → CIBLÉ FAIBLE ✓)
- [x] mettre à jour TASK-132
- [ ] commit + push
- [ ] check-task.sh TASK-132
- [ ] finalize-task.sh TASK-132
- [ ] ne pas merger sans validation cockpit

---

# Progress Log

## 2026-05-24 08:22:19 Europe/Paris

Task créée.

## 2026-05-24 08:50:00 Europe/Paris

Analyse préalable HomeController :
- membres() : 3 usages withoutGlobalScopes() (lignes 46, 47, 49) — avec re-filtre community_id
- exchanges() : 1 usage withoutGlobalScopes() (ligne 76) — avec re-filtre community_id
- Tous couverts par T0754 (4 tests verts)

## 2026-05-24 09:10:00 Europe/Paris

**CAS 1 — Tests verts après remplacement ciblé.**

4 usages `withoutGlobalScopes()` → `withoutGlobalScope(BelongsToTenantScope::class)`.
Imports réordonnés par Pint. T0754 : 4/4 verts. Batch run 2 : 725/725.

Note batch run 1 : 2 échecs LoopCreationTest + LoopActivityTrackingTest — passent en isolation → ordre-dépendants pré-existants, non liés au patch HomeController.

Allowlist mise à jour : HomeController DETTE MOYEN → CIBLÉ FAIBLE.

---

# Tests

- [x] T0754DashboardMembersExchangesTenantSafetyTest : 4/4 verts
- [x] batch complet SQLite run 2 : 725/725

---

# Test Results

## T0754DashboardMembersExchangesTenantSafetyTest

```bash
php artisan test tests/Feature/T0754DashboardMembersExchangesTenantSafetyTest.php
```

**4 passed / 11 assertions / 2.12s — 0 failed**

| Test | Résultat |
|---|---|
| dashboard responds for user with resolved organization | ✓ PASS |
| dashboard does not show data from another organization | ✓ PASS |
| members lists only members from resolved organization | ✓ PASS |
| exchanges lists only completed transactions from resolved organization | ✓ PASS |

## Batch SQLite complet

- Run 1 : 723 passed + 2 ordre-dépendants (LoopCreation, LoopActivity) — non liés au patch
- Run 2 : **725 passed / 1585 assertions / 84.48s — 0 failed**

Les 2 échecs du run 1 sont pré-existants (passent en isolation, ordre-dépendants).

---

# Review Notes

## Décision T132

**CAS 1 — Remplacement ciblé, tests verts.**

### Fichiers modifiés

- `app/Http/Controllers/HomeController.php` — 4 remplacements + import ajouté
- `docs/architecture/withoutGlobalScope-allowlist.md` — HomeController : DETTE MOYEN → CIBLÉ FAIBLE

### Changement appliqué

```php
// Avant (withoutGlobalScopes — global)
fn ($q) => $q->withoutGlobalScopes()->where(...)
Transaction::withoutGlobalScopes()

// Après (withoutGlobalScope — ciblé)
fn ($q) => $q->withoutGlobalScope(BelongsToTenantScope::class)->where(...)
Transaction::withoutGlobalScope(BelongsToTenantScope::class)
```

### Impact

- Aucun comportement fonctionnel changé
- Seul `BelongsToTenantScope` est bypassé (pas les scopes futurs)
- Re-filtre `community_id` maintenu dans tous les cas
- Tests T0754 verts, batch complet stable

### Recommandation merge

T132 est prêt pour COCKPIT. Changement minimal, sans risque.
