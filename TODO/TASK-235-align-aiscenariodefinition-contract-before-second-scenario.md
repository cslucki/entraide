---
task_id: TASK-235
title: Align AiScenarioDefinition contract before second scenario

status: TESTING

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-235-align-aiscenariodefinition-contract-before-second-scenario

priority: MEDIUM

created_at: 2026-06-10 21:17:11 Europe/Paris
updated_at: 2026-06-10 21:40:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: VERIFICATOR
  since: 2026-06-10 21:40:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Aligner le contrat `AiScenarioDefinition` pour qu'il puisse accueillir un second scénario (ex: `clarify_help_request`, `member_profile`) sans casser l'existant.

Cette TASK est une **réponse directe aux réserves VERIFICATOR** de TASK-234 :
- Réserve 1 : `systemPrompt()` et `jsonSchema()` sont implémentés sur `SupervisionContentScenario` mais absents de l'interface `AiScenarioDefinition`.
- Réserve 2 (non traitée ici) : duplication prompt/schema entre `SupervisionContentScenario` et `OpenAiSupervisionProvider` — documentée pour TASK future.

---

# Planned Actions

## Phase 1 — Étendre `AiScenarioDefinition`

- [ ] Ajouter `systemPrompt(): string` à l'interface
- [ ] Ajouter `jsonSchema(): array` à l'interface
- [ ] Ajouter `parseResponse(array $parsed, array $rawBody): AiScenarioResult` — **optionnel**, à décider

### Justification des méthodes

| Méthode | Obligatoire | Raison |
|---|---|---|
| `systemPrompt()` | OUI | Chaque scénario définit son propre prompt. Le provider doit pouvoir le consommer via l'interface. |
| `jsonSchema()` | OUI | Chaque scénario définit son propre schéma de réponse structurée. Le provider doit le transmettre à l'API AI. |
| `parseResponse()` | NON | Le provider `OpenAiSupervisionProvider` déjà parse la réponse. Cette méthode serait utile si on veut décentraliser le parsing, mais elle n'est pas nécessaire pour un second scénario supervision-like. |

**Décision proposée** : ajouter `systemPrompt()` et `jsonSchema()` uniquement. `parseResponse()` reste hors scope pour garder la TASK minimaliste.

## Phase 2 — Vérifier `SupervisionContentScenario`

- [ ] Vérifier que `SupervisionContentScenario` satisfait déjà l'interface étendue (déjà implémenté dans TASK-234)
- [ ] Aucune modification fonctionnelle attendue sur cette classe

## Phase 3 — Vérifier `AiScenarioFactory`

- [ ] `resolve()` retourne `?AiScenarioDefinition` — c'est acceptable v1
- [ ] Option : documenter le comportement `null` dans les tests
- [ ] Pas d'exception : garder le contrat `nullable` pour éviter un breaking change

## Phase 4 — Tests

- [ ] Ajouter test : `test_scenario_definition_has_system_prompt_method()` — vérifie que l'interface requiert `systemPrompt()`
- [ ] Ajouter test : `test_scenario_definition_has_json_schema_method()` — vérifie que l'interface requiert `jsonSchema()`
- [ ] Ajouter test : `test_supervision_content_scenario_implements_full_contract()` — vérifie que `SupervisionContentScenario` satisfait l'interface étendue
- [ ] Préserver `AiScenarioFactoryTest` existant (7 tests, 30 assertions)
- [ ] Préserver `AdminAiSupervisionTest` (17 tests, 66 assertions)

## Phase 5 — Validation compatibilité

- [ ] Vérifier que `OpenAiSupervisionProvider` n'est pas cassé
- [ ] Vérifier que `/admin/ai-supervision` reste fonctionnel
- [ ] Pas de changement route/UI

---

# Fichiers à modifier

| Fichier | Action | Lignes approx |
|---|---|---|
| `app/Services/Ai/Contracts/AiScenarioDefinition.php` | **Modifier** — ajouter `systemPrompt()` et `jsonSchema()` | +2 lignes |
| `app/Services/Ai/Scenarios/SupervisionContentScenario.php` | **Lire seulement** — déjà compatible, pas de changement | 0 |
| `tests/Unit/Services/Ai/AiScenarioFactoryTest.php` | **Modifier** — ajouter 3 tests ciblés interface | ~40 lignes |
| `app/Services/Ai/AiScenarioFactory.php` | **Lire seulement** — pas de changement | 0 |
| `app/Services/Ai/DTO/AiScenarioResult.php` | **Lire seulement** — pas de changement | 0 |
| `app/Providers/AppServiceProvider.php` | **Lire seulement** — pas de changement | 0 |

---

# Stratégie de compatibilité

### Backward compatibility

- `SupervisionContentScenario` implémente déjà `systemPrompt()` et `jsonSchema()` — pas de breaking change.
- `AiScenarioFactory::resolve()` conserve son retour `?AiScenarioDefinition` — pas de breaking change.
- Aucun consommateur externe de l'interface n'existe encore (hors tests et provider interne).

### Forward compatibility

- Un futur scénario `clarify_help_request` n'aura qu'à implémenter `AiScenarioDefinition` et fournir `systemPrompt()` + `jsonSchema()`.
- Le provider `OpenAiSupervisionProvider` pourra à terme consommer `systemPrompt()` et `jsonSchema()` via l'interface au lieu de les hardcoder.

---

# Risques

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| `SupervisionContentScenario` ne satisfait pas l'interface étendue | Faible | Faible | Vérification préalable dans cette planification. Déjà implémenté. |
| Tests existants cassés par l'ajout d'interface | Faible | Faible | PHP interface enforcement est compile-time ; pas de runtime break. |
| `parseResponse()` manquante bloque futur scénario | Faible | Faible | Documenté comme réservation pour TASK future. Non bloquant pour v1. |
| Scope creep vers refactor du provider | Moyenne | Moyenne | **Hors scope explicite** : pas de modification `OpenAiSupervisionProvider`. |

---

# Recommandation Build

**OUI** — Build recommandé, mais uniquement par CODEUR, sous coordination ORCH.

Justification :
- Pas de changement UI/asset
- Pas de migration
- Pas de nouvelle dépendance
- Seul changement : +2 méthodes dans une interface PHP + 3 tests unitaires

---

# Progress Log

## 2026-06-10 21:17:11 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-235-align-aiscenariodefinition-contract-before-second-scenario

Status:
IN_PROGRESS

## 2026-06-10 21:30:00 Europe/Paris

Plan détaillé rédigé. Mode PLAN uniquement — attente validation Cyril / Cockpit avant tout code.

- Branche : `TASK-235-align-aiscenariodefinition-contract-before-second-scenario`
- Fichiers à modifier : 1 (interface) + 1 (tests)
- Fichiers en lecture seule : 4 (SupervisionContentScenario, Factory, DTO, Provider)
- Risques : documentés, faibles
- Build : recommandé, par CODEUR sous coordination ORCH (validé Cockpit)
- Prochaine étape : validation Cyril / Cockpit → passage en mode exécution (CODEUR)

## 2026-06-10 21:35:00 Europe/Paris

Validation Cyril / Cockpit reçue.
- Plan TASK-235 validé.
- Correction : "Build non recommandé" → "Build recommandé, par CODEUR sous coordination ORCH".
- Dérive `OutilsCoversables.md` signalée et ignorée (hors scope, fichier non créé).
- Prochaine étape : GO CODEUR pour exécution bornée.

## 2026-06-10 21:40:00 Europe/Paris

Phase 1 — Étendre `AiScenarioDefinition` : COMPLETE.
- Ajouté `systemPrompt(): string` à l'interface
- Ajouté `jsonSchema(): array` à l'interface
- Fichier modifié : `app/Services/Ai/Contracts/AiScenarioDefinition.php` (+2 lignes)

Phase 2 — Vérifier `SupervisionContentScenario` : COMPLETE.
- `SupervisionContentScenario` satisfait déjà l'interface étendue (déjà implémenté dans TASK-234)
- Aucune modification fonctionnelle sur cette classe

Phase 3 — Vérifier `AiScenarioFactory` : COMPLETE.
- `resolve()` retourne `?AiScenarioDefinition` — pas de changement nécessaire
- Contrat `nullable` conservé pour éviter un breaking change

Phase 4 — Tests : COMPLETE.
- Ajouté `test_scenario_definition_has_system_prompt_method()` — vérifie que l'interface requiert `systemPrompt()`
- Ajouté `test_scenario_definition_has_json_schema_method()` — vérifie que l'interface requiert `jsonSchema()`
- Ajouté `test_supervision_content_scenario_implements_full_contract()` — vérifie que `SupervisionContentScenario` satisfait l'interface étendue

Phase 5 — Validation compatibilité : COMPLETE.
- `AiScenarioFactoryTest` : 10 tests passés (43 assertions) — 3 nouveaux tests, 0 régression
- `AdminAiSupervisionTest` : 17 tests passés (66 assertions) — 0 régression
- `OpenAiSupervisionProvider` non cassé — pas de modification
- `/admin/ai-supervision` reste fonctionnel — pas de changement route/UI

---

# Test Results

- [x] Tests unitaires existants préservés — `AiScenarioFactoryTest` (10 tests, 43 assertions, +3 tests)
- [x] Tests feature existants préservés — `AdminAiSupervisionTest` (17 tests, 66 assertions)
- [x] Tests ciblés interface ajoutés — 3 tests passés
- [x] Pas de changement UI / route
- [x] Pas de changement tenant scope

---

# Modified Files

- `app/Services/Ai/Contracts/AiScenarioDefinition.php` — ajout de `systemPrompt()` et `jsonSchema()`
- `tests/Unit/Services/Ai/AiScenarioFactoryTest.php` — ajout de 3 tests unitaires

---

# Review Notes

**Verdict CODEUR** : TASK-235 complète. Prête pour review VERIFICATOR.

Critères de validation :
- [x] Scope minimal respecté
- [x] `parseResponse()` différé (hors scope, documenté)
- [x] `resolve()` null conservé (pas de breaking change)
- [x] Build non nécessaire (pas de changement asset/UI)
- [x] Tests verts — 27 tests, 109 assertions totales
- [x] Pas de régression détectée

# Handoffs

# Tests

- [ ] Tests unitaires existants préservés — `AiScenarioFactoryTest` (7 tests)
- [ ] Tests feature existants préservés — `AdminAiSupervisionTest` (17 tests)
- [ ] Tests ciblés interface ajoutés — 3 tests attendus
- [ ] Pas de changement UI / route
- [ ] Pas de changement tenant scope

---

# Test Results

Pending — tests à exécuter après validation plan et passage en exécution.

---

# Review Notes

**Verdict attendu** : Cyril / Cockpit validation du plan avant exécution.

Critères de validation :
- Scope suffisamment minimal ?
- `parseResponse()` doit-il être inclus maintenant ou différé ?
- `resolve()` null vs exception — décision confirmée ?
- Build nécessaire ou non ?

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`