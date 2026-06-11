---
task_id: TASK-241
title: Admin AI provider selector with Ollama default

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-241-admin-ai-provider-selector-with-ollama-default

priority: MEDIUM

created_at: 2026-06-11 05:53:59 Europe/Paris
updated_at: 2026-06-11 06:22:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 06:22:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Corriger l'interface `/admin/ai-supervision` pour utiliser réellement les 3 providers (Ollama, OpenRouter, OpenAI) avec un sélecteur `provider` séparé du champ `model`. Ollama par défaut si activé.

---

# Planned Actions

- [x] Créer `SupervisionProviderResolver` — résout le provider depuis le container selon le nom
- [x] Modifier `AdminAiSupervisionController` — ajouter champ `provider`, router vers le bon provider, adapter clarify_help_request
- [x] Modifier `resources/views/admin/ai-supervision/index.blade.php` — sélecteur provider, textes neutres, modèles par provider
- [x] Ajouter tests feature pour chaque provider
- [x] Vérifier régression tests existants

---

# Architecture

## SupervisionProviderResolver (service)

Résout un `SupervisionProvider` depuis le container selon le nom (`ollama`, `openrouter`, `openai`).

```php
class SupervisionProviderResolver {
    public function resolve(string $provider): SupervisionProvider;
    public function defaultProvider(): string;
    public function availableProviders(): array;
}
```

- `ollama` → `OllamaSupervisionProvider` (si `ai.ollama.enabled`)
- `openrouter` → `OpenRouterSupervisionProvider` (si `ai.openrouter.enabled`)
- `openai` → `SupervisionProvider` (binding existant, wrappé dans LoggingSupervisionProvider)
- `defaultProvider()` : ollama si enabled, sinon openrouter si enabled, sinon openai

## Controller

- `AVAILABLE_PROVIDERS` : `['ollama', 'openrouter', 'openai']`
- `PROVIDER_MODELS` : modèles par provider
- Validation : `provider` in AVAILABLE_PROVIDERS
- `analyze()` : résout le provider, appelle `supervise()` pour les deux scénarios
- `clarify_help_request` : utilise le provider résolu (plus de HTTP direct OpenAI)

## View

- Sélecteur `provider` avant `model`
- Texte header neutre : "Laboratoire IA interne BouclePro…"
- Modèles filtrés par provider sélectionné
- Défaut : `clarify_help_request`

---

# Fichiers

| Fichier | Action |
|---|---|
| `app/Services/Ai/SupervisionProviderResolver.php` | CREER |
| `app/Http/Controllers/Admin/AdminAiSupervisionController.php` | MODIFIER |
| `resources/views/admin/ai-supervision/index.blade.php` | MODIFIER |
| `tests/Feature/Admin/AdminAiSupervisionTest.php` | MODIFIER (+tests provider) |

---

# Progress Log

## 2026-06-11 05:53:59 Europe/Paris

Task created.

Owner: OPENCODE
Branch: TASK-241-admin-ai-provider-selector-with-ollama-default
Status: IN_PROGRESS

## 2026-06-11 06:15:00 Europe/Paris

CODEUR (OPENCODE) commence le travail.
- Lecture conversation TASK-241
- Analyse des tests existants
- 7 tests échouaient sur `assertSee` / `data_get` null

## 2026-06-11 06:18:00 Europe/Paris

Root cause identifiée : default scenario du controller modifié par ORCH en `clarify_help_request`, mais les tests legacy ne spécifiaient pas de `scenario` → attendaient implicitement `supervision_content`.

Fix appliqué : ajout `scenario => 'supervision_content'` aux 7 tests concernés :
- `test_skills_enum_in_schema_reflects_taxonomy_from_config`
- `test_category_enum_in_schema_reflects_taxonomy_from_config`
- `test_free_form_category_strings_are_not_rendered_as_controlled_taxonomy`
- `test_supervision_content_still_works_after_adding_new_scenario`
- `test_writer_content_maps_to_redaction_category`
- `test_unmatched_terms_are_displayed_separately`
- `test_needs_human_review_flag_is_shown_when_mapping_incomplete`

Tests passent : 21/21.

## 2026-06-11 06:20:00 Europe/Paris

Ajout des tests manquants pour ORCH :
- `test_default_provider_is_ollama_when_enabled`
- `test_default_provider_is_openai_when_ollama_disabled`
- `test_clarify_help_request_is_not_supported_with_ollama`
- `test_clarify_help_request_is_not_supported_with_openrouter`
- `test_supervision_content_with_ollama_provider`
- `test_supervision_content_with_openrouter_provider`

1 test échoue (OpenRouter) : API key manquante dans config.
Fix : ajout `ai.openrouter.api_key` dans le test.

Tests passent : 27/27.

## 2026-06-11 06:22:00 Europe/Paris

Task DONE.
Tous tests passent. Aucune régression.

---

# Handoffs

# Tests

- [x] feature tests (27/27 passent)
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

2026-06-11 06:22:00 Europe/Paris
AdminAiSupervisionTest : 27 passed (105 assertions), 0 failed, 0 errors.
Duration: 2.37s

---

# Review Notes

- Aucune régression détectée
- Tests legacy fixés par ajout de `scenario` explicite
- Nouveaux tests ajoutés pour couvrir tous les providers
- clarify_help_request ne supporte que OpenAI (exception explicite pour Ollama/OpenRouter)

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`