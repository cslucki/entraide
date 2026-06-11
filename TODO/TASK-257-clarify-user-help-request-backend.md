---
task_id: TASK-257
status: DONE
owner: ORCH

contributors:
  - ORCH

lock:
  status: UNLOCKED

branch: TASK-257-clarify-user-help-request-backend
---

# TASK-257 — Backend clarification demande aide utilisateur

## Objectif

Créer un service backend qui utilise le vrai `ClarifyHelpRequestScenario` (via SupervisionProviderResolver) pour analyser les intentions d'aide des utilisateurs, en remplacement du `FakeAIProvider` (keyword matching).

## Périmètre

4 fichiers : service + config + binding provider + tests. Hors scope : vues, job async, notification, admin UI.

### Fichiers

| Fichier | Action |
|---------|--------|
| `app/Services/Ai/ClarifyUserHelpRequestService.php` | CRÉÉ — Implémente AiProvider, runScenario + mapping |
| `app/Providers/AppServiceProvider.php` | MODIFIÉ — Binding AiProvider → nouveau service |
| `config/ai.php` | MODIFIÉ — Feature flag `clarify.enabled` |
| `tests/Feature/Services/Ai/ClarifyUserHelpRequestServiceTest.php` | CRÉÉ — Tests du service |

## Critères de succès

- [x] Service implémente `AiProvider` (contrat existant)
- [x] Feature flag `config('ai.clarify.enabled')` contrôle activation
- [x] Mapping complet ClarifyHelpRequestScenario → AssistedInteractionLabResult
- [x] Fallback vers `FakeAIProvider` si flag désactivé / pas de provider dispo / pas de scenario
- [x] Interaction persistée dans `admin_ai_interactions` (via LoggingSupervisionProvider)
- [x] Tests : 14 tests (82 assertions)
- [x] LoopHelpRequestTest : 19/19 verts (0 régression)
- [x] Feature/Admin/ : 250/250 verts (0 régression)

## Risques

- Latence API synchrone : 2-15s pour l'utilisateur
- Coût API si `clarify.enabled` activé sans prévenir
- Mapping imparfait entre les 2 schémas de sortie

## Tests

```bash
php artisan test tests/Feature/Services/Ai/ClarifyUserHelpRequestServiceTest.php  # 14/14
php artisan test tests/Feature/LoopHelpRequestTest.php                             # 19/19
php artisan test tests/Feature/Admin/ --quiet                                      # 250/250
```

## Progress Log

### 2026-06-11 — Implementation
- Création TASK file + branche depuis develop
- Création ClarifyUserHelpRequestService (implemente AiProvider, 3 fallback paths, mapping vers AssistedInteractionLabResult)
- Modification AppServiceProvider (binding closure avec SupervisionProviderResolver + AiScenarioFactory + FakeAIProvider)
- Modification config/ai.php (section clarify.enabled avec env AI_CLARIFY_ENABLED)
- Écriture 14 tests (feature flag, fallback, high/low confidence, human review, empty result, help_type mapping, toArray keys)
- DB reset nécessaire (bouclepro_test corrompu)
- LoopHelpRequestTest 19/19 ✅
- Admin tests 250/250 ✅
- Prêt pour merge

## Modified Files

- app/Services/Ai/ClarifyUserHelpRequestService.php
- app/Providers/AppServiceProvider.php
- config/ai.php
- tests/Feature/Services/Ai/ClarifyUserHelpRequestServiceTest.php
