---
task_id: TASK-131
title: t131-sqlite-batch-stability-audit

status: DONE

owner: CODEX

contributors: []

branch: TASK-131-t131-sqlite-batch-stability-audit

priority: MEDIUM

created_at: 2026-05-24 08:08:05 Europe/Paris
updated_at: 2026-05-24 08:30:00 Europe/Paris

labels:
  - sqlite
  - testing
  - audit
  - stability

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-24 08:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Auditer les échecs SQLite batch récurrents et produire une stratégie de stabilisation sans patch large.

Plusieurs tâches précédentes ont observé des échecs batch SQLite :memory: préexistants.
Ils ne bloquent pas la CI PostgreSQL, mais polluent la confiance locale.
L'objectif est de comprendre précisément : quels tests échouent, pourquoi, et quelle stratégie adopter.

---

# Hors scope

- pas de correction massive
- pas de migration DB
- pas de refactor tenant
- pas de modification BelongsToTenantScope
- pas de changement community_id / organization_id
- pas de suppression de tests
- pas de patch runtime sans validation cockpit
- pas de cleanup branches
- pas de footer version
- pas de main / PROD

---

# Planned Actions

## Phase A — Audit batch complet

- [x] lancer php artisan test (batch complet SQLite) — Run 1 : 725 passed / 88.15s
- [x] capturer tous les tests rouges — AUCUN
- [x] identifier les suites/fichiers en échec — AUCUN

## Phase B — Isolation diagnostique

- [x] lancer chaque suite rouge en isolé — N/A (aucun rouge)
- [x] comparer résultat isolé vs batch — N/A
- [x] identifier les tests ordre-dépendants — N/A
- [x] Run 2 consécutif : 725 passed / 96.46s — reproductible

## Phase C — Analyse des causes

- [x] inspecter phpunit.xml / phpunit.pgsql.xml — SQLite :memory: correct
- [x] vérifier configuration DB test — RefreshDatabase sur :memory: stable
- [x] chercher dépendances PostgreSQL implicites (ILIKE, ::text, jsonb) — ILIKE gardé par getDriverName()
- [x] chercher RefreshDatabase vs LazilyRefreshDatabase — 0 LazilyRefreshDatabase, 64 RefreshDatabase
- [x] chercher seeders non compatibles SQLite — DemoSeeder hors runtime tests
- [x] chercher migrations conditionnelles PostgreSQL — aucune
- [x] documenter historique CSRF 419 (T121) comme cause réelle des échecs passés

## Phase D — Rapport

- [x] produire docs/audits/T131-sqlite-batch-stability-audit.md
- [x] classer chaque échec — RÉSOLU (CSRF T121), aucun SQLite-spécifique
- [x] recommandation finale — maintenir statu quo, aucun patch nécessaire

## Phase E — Finalisation

- [x] mettre à jour TASK-131
- [ ] commit + push
- [ ] check-task.sh TASK-131
- [ ] finalize-task.sh TASK-131
- [ ] ne pas merger sans validation cockpit

---

# Progress Log

## 2026-05-24 08:08:05 Europe/Paris

Task créée. Branch TASK-131-t131-sqlite-batch-stability-audit depuis develop propre (post-merge T130, e7b12c0).

## 2026-05-24 08:30:00 Europe/Paris

**Découverte clé :** Batch SQLite entièrement vert — 725 passed / 1585 assertions, deux runs consécutifs stables.

Les "échecs SQLite batch récurrents" des tâches précédentes étaient 100% des CSRF 419 failures, corrigées en TASK-121 (commit `bcd3ced`) par `PreventRequestForgery::class` dans `TestCase::setUp()`. Aucun problème SQLite-spécifique trouvé.

Analyse exhaustive : configuration phpunit.xml, RefreshDatabase (0 LazilyRefreshDatabase), ILIKE guardé par `getDriverName()`, aucune migration conditionnelle PostgreSQL, aucun seeder incompatible dans les tests.

Rapport produit : `docs/audits/T131-sqlite-batch-stability-audit.md`.

---

# Tests

- [x] audit batch SQLite complet — 725 passed / 1585 assertions (×2 runs)
- [x] isolation suite par suite — N/A (aucun rouge en batch)
- [x] comparaison isolé vs batch — N/A

---

# Test Results

## Batch SQLite (:memory:) — Run 1

```bash
php artisan test --configuration=phpunit.xml
```

**725 passed / 1585 assertions / 88.15s — 0 failed**

## Batch SQLite (:memory:) — Run 2 (reproductibilité)

**725 passed / 1585 assertions / 96.46s — 0 failed**

---

# Review Notes

## Résultat T131

**Batch SQLite entièrement stable. Aucun problème actif.**

### Cause historique des échecs (résolue)

Les échecs batch SQLite observés dans les tâches précédentes étaient 100% des **CSRF 419 failures** (149 tests), causées par `withoutMiddleware(ValidateCsrfToken::class)` silencieusement inefficace sous Laravel 11.

**Fix (TASK-121, commit `bcd3ced`) :** `$this->withoutMiddleware(PreventRequestForgery::class)` dans `TestCase::setUp()`.

### État actuel

| Métrique | Valeur |
|---|---|
| Tests totaux | 725 |
| Assertions | 1585 |
| Tests rouges | 0 |
| Tests skippés | 0 |
| Runs stables consécutifs | 2 |

### Recommandation

Maintenir le statu quo. SQLite `:memory:` + `RefreshDatabase` = stack locale rapide et stable. PostgreSQL CI via `phpunit.pgsql.xml` = parité production. Aucun patch, aucune quarantine, aucun abandon SQLite batch nécessaire.

### Prochaine tâche proposée

Aucune tâche SQLite nécessaire. Prochaine priorité selon roadmap T128 (étape 3) : nettoyage `withoutGlobalScopes()` global dans `HomeController` → devrait utiliser la forme ciblée `withoutGlobalScope(BelongsToTenantScope::class)`. Risque faible, hors scope T131.
