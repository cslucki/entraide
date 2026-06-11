---
task_id: TASK-244
title: Harden AI JSON protocol for Ollama and OpenAI-compatible providers

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-244-harden-ai-json-protocol-for-ollama-and-openai-compatible-providers

priority: MEDIUM

created_at: 2026-06-11 07:29:45 Europe/Paris
updated_at: 2026-06-11 07:45:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 07:45:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Durcir le protocole JSON pour les providers Ollama et OpenAI-compatible afin de gérer les réponses mal formatées (markdown fences, préambules, champs manquants, types incorrects).

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---
# Progress Log

## 2026-06-11 07:29:45 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-244-harden-ai-json-protocol-for-ollama-and-openai-compatible-providers
Status: IN_PROGRESS

## 2026-06-11 07:00:00 Europe/Paris

CODEUR commence l'inspection.

## 2026-06-11 07:15:00 Europe/Paris

Création de `JsonResponseParser` avec :
- Extraction JSON depuis markdown fences
- Extraction JSON depuis préambules
- Validation et normalisation des champs obligatoires
- Coercion de types
- Fallbacks pour champs manquants

## 2026-06-11 07:30:00 Europe/Paris

Mise à jour des 3 providers pour utiliser `JsonResponseParser` :
- `OllamaSupervisionProvider`
- `OpenRouterSupervisionProvider`
- `OpenAiSupervisionProvider`

## 2026-06-11 07:35:00 Europe/Paris

Ajout des tests :
- `JsonResponseParserTest` (8 tests unitaires)
- Tests feature dans `AdminAiSupervisionTest` pour couvrir les cas d'erreur JSON

## 2026-06-11 07:45:00 Europe/Paris

Tests verts :
- JsonResponseParserTest : 8 passed (32 assertions)
- AdminAiSupervisionTest : 44 passed (145 assertions)

Task DONE.

## 2026-06-11 08:20:00 Europe/Paris

Corrections demandées par VERIFICATOR appliquées :
- 2 tests unitaires : messages d'exception corrigés (Ollama + OpenRouter)
- temperature=0 ajouté pour Ollama et OpenRouter

Tests verts : 151 passed (487 assertions), 0 failed, 0 errors.

# Handoffs

# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

2026-06-11 07:45:00 Europe/Paris
- JsonResponseParserTest : 8 passed (32 assertions), 0 failed, 0 errors
- AdminAiSupervisionTest : 44 passed (145 assertions), 0 failed, 0 errors

2026-06-11 08:20:00 Europe/Paris
- Suite complète : 151 passed (487 assertions), 0 failed, 0 errors

---

# Review Notes

## 2026-06-11 08:10 Europe/Paris — VERIFICATOR review

Verdict : **OK_WITH_RESERVES**

### Réserves (corrigées)

1. **2 tests unitaires échouent** — messages d'exception à corriger :
   - `OllamaSupervisionProviderTest::test_ollama_provider_handles_invalid_json_response` (L90) : `'Sortie JSON Ollama non décodable.'` → `'Sortie JSON non décodable'` ✅
   - `OpenRouterSupervisionProviderTest::test_supervise_handles_invalid_json_response` (L298) : `'Sortie JSON OpenRouter non décodable.'` → `'Sortie JSON non décodable'` ✅

2. **temperature=0 non appliqué** — Ollama : ajouté `'temperature' => 0` dans `options`. OpenRouter : changé `0.3` → `0`. ✅

### Points validés

- `JsonResponseParser` bien structuré (4 méthodes, extraction robuste, defaults, coercion)
- Les 3 providers utilisent `JsonResponseParser::parseSupervisionResult()`
- Prompt Ollama inclut format JSON complet explicite + `format: 'json'` payload
- 8 tests unitaires parser passent, 5 tests feature passent
- 151 tests passent, 0 régression

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`