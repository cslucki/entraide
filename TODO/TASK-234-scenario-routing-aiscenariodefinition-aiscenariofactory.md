---
task_id: TASK-234
title: Scenario Routing — AiScenarioDefinition & AiScenarioFactory

status: MERGED

owner: OPENCODE

contributors:
  - VERIFICATOR

branch: TASK-234-scenario-routing-aiscenariodefinition-aiscenariofactory

priority: MEDIUM

created_at: 2026-06-10 20:01:34 Europe/Paris
updated_at: 2026-06-10 21:20:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-10 21:20:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---
# Objective

Implémenter le socle minimal de Scenario Routing — version resserrée validée par Cyril / Cockpit.

Scope restreint :
- `AiScenarioDefinition` — interface minimale (pas value object générique)
- `AiScenarioResult` — DTO dans `app/Services/Ai/DTO/` enveloppant `AiSupervisionResult`
- `AiScenarioFactory` — registry minimal résolvant les scénarios par ID
- `SupervisionContentScenario` — implémentation concrète du scénario de supervision existant
- Binding container pour `AiScenarioFactory`
- Pas de modification de `OpenAiSupervisionProvider::supervise()` ni de la route `/admin/ai-supervision`
- Pas de DB, pas de migration, pas de templating `{{variable}}`, pas de `buildPayload()` générique
- Pas de `clarify_help_request`, pas OpenRouter/Ollama, pas benchmark logger
- Pas annuaire, pas ChatLoop, pas feature publique, pas main/PROD
- Pas de nouveau code Community

---

# Planned Actions

## Phase 1 — AiScenarioDefinition (interface minimale)

- [x] Créer `app/Services/Ai/Contracts/AiScenarioDefinition.php`
  - `string id()` — slug unique du scénario
  - `string name()` — nom lisible
  - `?string description()`
  - `string providerHint()` — hint vers le provider (ex: 'openai')

## Phase 2 — AiScenarioResult

- [x] Créer `app/Services/Ai/DTO/AiScenarioResult.php`
  - Enveloppe `AiSupervisionResult` (existant dans `app/Services/Ai/DTO/AiSupervisionResult.php`)
  - Ajoute : `?string $scenarioId`, `?array $scenarioMeta`
  - Ajoute : `?float $executionTimeMs`, `?int $promptTokensUsed`, `?int $completionTokensUsed`
  - Méthode statique : `fromSupervisionResult(AiSupervisionResult $result, AiScenarioDefinition $definition, ?float $executionTimeMs = null): self`
  - Méthode : `toArray()` — merge les champs de `AiSupervisionResult` + métadonnées scénario

## Phase 3 — AiScenarioFactory (registry minimal)

- [x] Créer `app/Services/Ai/AiScenarioFactory.php`
  - `register(AiScenarioDefinition $scenario): void` — enregistre par `$scenario->id()`
  - `resolve(string $id): ?AiScenarioDefinition` — résolution par ID
  - `all(): array` — retourne tous les scénarios enregistrés
  - Binding singleton dans `AppServiceProvider` avec `SupervisionContentScenario` pré-enregistré

## Phase 4 — SupervisionContentScenario

- [x] Créer `app/Services/Ai/Scenarios/SupervisionContentScenario.php`
  - Implémente `AiScenarioDefinition`
  - `id()` → `'supervision_content'`
  - `name()` → `'Supervision de contenu'`
  - `description()` → résumé du scénario
  - `providerHint()` → `'openai'`
  - `systemPrompt(): string` — contient le BASE_SYSTEM_PROMPT + taxonomie (logique extraite du provider existant)
  - `jsonSchema(): array` — schéma JSON de supervision (logique extraite du provider existant)
  - `loadTaxonomy(): array` — fallback config/DB (logique extraite du provider existant)
  - Pas de `buildPayload()` générique — le scénario expose ses propres données ; le provider reste inchangé

## Phase 5 — Tests

- [x] Ajouter test unitaire ciblé `tests/Unit/Services/Ai/AiScenarioFactoryTest.php`
  - Vérifie que le factory résout `supervision_content`
  - Vérifie `AiScenarioResult::fromSupervisionResult`
  - Vérifie métadonnées du scénario
- [x] Préserver tous les tests existants (notamment `AdminAiSupervisionTest`)

## Non-goals (hors scope)

- Base de données / modèle Eloquent pour les scénarios
- Interface admin / CRUD / nouvelle route HTTP
- Benchmark logger fichier
- JSON schemas de scénarios métier (seront définis dans une TASK ultérieure)
- `buildPayload()` générique ou templating `{{variable}}`
- `AiSupervisionRequest` (n'existe pas)
- `clarify_help_request` scénario
- OpenRouter, Ollama
- Refactor du provider existant (conservé tel quel pour compatibilité)
- Feature publique, annuaire, ChatLoop

---

# Progress Log

## 2026-06-10 20:01:34 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-234-scenario-routing-aiscenariodefinition-aiscenariofactory
Status: IN_PROGRESS

## 2026-06-10 20:45:00 Europe/Paris

Plan détaillé rédigé — attente validation Cyril.

## 2026-06-10 21:00:00 Europe/Paris

Plan resserré validé par Cyril / Cockpit. Passage en mode exécution.

## 2026-06-10 21:45:00 Europe/Paris

Implémentation terminée.
- Fichiers créés : AiScenarioDefinition, AiScenarioResult, AiScenarioFactory, SupervisionContentScenario
- Fichier modifié : AppServiceProvider (binding singleton factory)
- Test créé : AiScenarioFactoryTest (7 tests, 30 assertions)
- Tests existants : AdminAiSupervisionTest (17 tests, 66 assertions) — tous verts
- Pas de changement UI/route
- Pas de changement OpenAiSupervisionProvider
- Pas de DB/migration

## 2026-06-10 21:22:00 Europe/Paris

Commit et push effectués.
- Commit : `5331b9b` — task: scenario routing foundation
- Branche poussée sur origin
- Verdict VERIFICATOR : OK_WITH_RESERVES (réserves documentées dans Review Notes)
- Prochaine étape : merge dans develop

## 2026-06-10 21:25:00 Europe/Paris

Merge dans develop effectué.
- Merge commit : `849223a` (branche TASK-234) -> develop
- Version bump : v0.227-alpha -> v0.234-alpha
- Status : MERGED
- Branch : prête à suppression

# Handoffs

# Tests

- [x] feature tests existants préservés — 17/17 passés
- [x] test unitaire factory/scenario ajouté — 7/7 passés
- [x] browser validation (pas de changement UI)
- [x] responsive validation (pas de changement UI)
- [x] console inspection (pas de changement UI)
- [x] tenant validation (pas de changement de scope)

---

# Test Results

2026-06-10 21:45:00 Europe/Paris

```bash
$ APP_ENV=testing DB_DATABASE=bouclepro_test vendor/bin/phpunit tests/Unit/Services/Ai/AiScenarioFactoryTest.php
OK (7 tests, 30 assertions)

$ APP_ENV=testing DB_DATABASE=bouclepro_test vendor/bin/phpunit tests/Feature/Admin/AdminAiSupervisionTest.php
OK (17 tests, 66 assertions)
```

---

# Review Notes

## Verdict VERIFICATOR — 2026-06-10 21:11 Europe/Paris

**Verdict : OK_WITH_RESERVES**

Le patch est prêt pour check/finalize/merge.

### Réserves documentées (non-bloquantes)

1. **Interface gap :** `systemPrompt()` et `jsonSchema()` sont implémentés sur `SupervisionContentScenario` mais absents de l'interface `AiScenarioDefinition`. L'interface minimale est cohérente avec le scope TASK, mais ces méthodes devront être ajoutées à l'interface avant l'implémentation d'un second scénario (clarify_help_request, member_profile).

2. **Duplication prompt/taxonomy/schema :** `SupervisionContentScenario` (`app/Services/Ai/Scenarios/SupervisionContentScenario.php:10-192`) et `OpenAiSupervisionProvider` (`app/Services/Ai/Providers/OpenAiSupervisionProvider.php:16-316`) partagent ~160 lignes quasi-identiques (BASE_SYSTEM_PROMPT, loadTaxonomy, systemPrompt/buidSystemPrompt, jsonSchema). Non dangereux car le provider est untouched, mais à extraire dans une TASK future.

### Détail de la review

| Point | Statut |
|---|---|
| Scope respecté (9/9 points) | ✓ |
| Compatibilité existante (4/4) | ✓ |
| Factory registry minimal | ✓ |
| Factory retourne null (acceptable v1) | ⚠️ |
| Tests unitaires (7 tests, 30 assertions) | ✓ |
| Tests feature préservés (17 tests, 66 assertions) | ✓ |

---

## Recommandation initiale

Recommandation : review VERIFICATOR recommandée pour valider la compatibilité du socle avec les futurs scénarios (clarify_help_request, member_profile, etc.) et l'absence de régression sur OpenAiSupervisionProvider.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`