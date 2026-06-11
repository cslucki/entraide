---
task_id: TASK-246
title: Make clarify_help_request provider-agnostic for Ollama and OpenRouter

status: IN_PROGRESS

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-246-make-clarify-help-request-provider-agnostic-for-ollama-and-openrouter

priority: HIGH

created_at: 2026-06-11 13:46:01 Europe/Paris
updated_at: 2026-06-11 13:58:00 Europe/Paris

labels:
  - ai
  - refactor
  - provider-agnostic
  - clarify_help_request

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-11 13:46:01 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Make the `clarify_help_request` scenario work with **all three providers** (Ollama, OpenRouter, OpenAI) instead of being locked to OpenAI only.

Currently, `clarify_help_request` is OpenAI-only at three enforcement layers:
1. **Controller** — hides scenario when OpenAI disabled, blocks POST, throws exception for non-OpenAI providers
2. **Resolver** — `supportedScenarios()` only maps `clarify_help_request` to `openai`
3. **Scenario definition** — `providerHint()` returns `'openai'`

The controller's `runClarifyHelpRequest()` method bypasses the `SupervisionProvider` interface entirely, making a direct HTTP call to the OpenAI Responses API (`/responses`). This architectural gap must be closed.

# Root Cause

`clarify_help_request` was implemented before Ollama/OpenRouter providers existed. It uses OpenAI Responses API features (`store: false`, `text.format.json_schema strict: true`) that have no equivalent in Ollama's `/api/generate` or OpenRouter's `/chat/completions`.

The `SupervisionProvider` interface only supports `supervision_content` (returns `AiSupervisionResult` DTO). There is no generic scenario execution method.

# Solution Design

**Option A (chosen):** Add `runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array` to the `SupervisionProvider` interface.

Each provider builds its own payload from `$scenario->systemPrompt()` and `$scenario->jsonSchema()`, reusing the existing endpoint patterns and `JsonResponseParser`.

This is simpler than Option B (new `ScenarioRunner` service) and aligns with the existing provider pattern.

# Planned Actions

- [ ] 1. Add `runScenario()` method to `SupervisionProvider` interface
- [ ] 2. Implement `runScenario()` in `OllamaSupervisionProvider` (uses `/api/generate` + `format: 'json'` + `think: false` + `JsonResponseParser`)
- [ ] 3. Implement `runScenario()` in `OpenRouterSupervisionProvider` (uses `/chat/completions` + `response_format: json_schema` + `JsonResponseParser`)
- [ ] 4. Implement `runScenario()` in `OpenAiSupervisionProvider` (refactored from current `runClarifyHelpRequest()` logic, uses `/responses` + `text.format.json_schema strict`)
- [ ] 5. Replace `runClarifyHelpRequest()` in controller with `$provider->runScenario($scenario, $content, $model)`
- [ ] 6. Update `SupervisionProviderResolver::supportedScenarios()` — `clarify_help_request` available for all providers
- [ ] 7. Update `ClarifyHelpRequestScenario::providerHint()` — return empty string or `null` (no provider preference)
- [ ] 8. Remove OpenAI-only guards in controller (lines 44-47, 85-89, 101-108)
- [ ] 9. Remove `test_clarify_help_request_is_not_supported_with_ollama` and `test_clarify_help_request_is_not_supported_with_openrouter` (no longer true)
- [ ] 10. Add new tests: `test_clarify_help_request_works_with_ollama`, `test_clarify_help_request_works_with_openrouter`
- [ ] 11. Update Blade template scenario compatibility (remove Ollama/OpenRouter exclusion)
- [ ] 12. Validate UI at `/admin/ai-supervision` with all three providers

# Impacted Files

| File | Change |
|------|--------|
| `app/Services/Ai/Contracts/SupervisionProvider.php` | Add `runScenario()` method signature |
| `app/Services/Ai/Providers/OllamaSupervisionProvider.php` | Implement `runScenario()` |
| `app/Services/Ai/Providers/OpenRouterSupervisionProvider.php` | Implement `runScenario()` |
| `app/Services/Ai/Providers/OpenAiSupervisionProvider.php` | Implement `runScenario()` |
| `app/Http/Controllers/Admin/AdminAiSupervisionController.php` | Replace `runClarifyHelpRequest()` with `$provider->runScenario()`, remove OpenAI-only guards |
| `app/Services/Ai/SupervisionProviderResolver.php` | Update `supportedScenarios()` |
| `app/Services/Ai/Scenarios/ClarifyHelpRequestScenario.php` | Change `providerHint()` to return empty/null |
| `resources/views/admin/ai-supervision/index.blade.php` | Minor — remove dynamic exclusion if applicable |
| `tests/Feature/Admin/AdminAiSupervisionTest.php` | Remove 2 exclusion tests, add 2 inclusion tests |
| `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php` | Add `runScenario` test |
| `tests/Unit/Services/Ai/OpenRouterSupervisionProviderTest.php` | Add `runScenario` test |

# Forbidden

- NO database migrations
- NO .env changes
- NO new model downloads
- NO `clarify_help_request` prompt changes
- NO massive refactor — stay within the listed files
- NO OpenAI reactivation (remains disabled by default)
- DO NOT change `supervision_content` behavior

# Design Constraints

- `runScenario()` returns `array` (raw decoded JSON), NOT `AiSupervisionResult`
- Each provider reuses its own endpoint pattern and `JsonResponseParser`
- Ollama: `/api/generate` + `format: 'json'` + `think: false` + system prompt in prompt field
- OpenRouter: `/chat/completions` + `response_format: { type: json_schema, json_schema: { name, strict, schema } }`
- OpenAI: `/responses` + `text.format.json_schema strict: true`
- `ClarifyHelpRequestScenario::jsonSchema()` provides the schema — providers must adapt it to their endpoint format
- Ollama cannot use `json_schema` strict mode — use `format: 'json'` with schema embedded in system prompt
- OpenRouter uses OpenAI-compatible `response_format` but via `/chat/completions`, not `/responses`

---
# Progress Log

## 2026-06-11 13:46:01 Europe/Paris

Task created.

Owner: OPENCODE

Branch: TASK-246-make-clarify-help-request-provider-agnostic-for-ollama-and-openrouter

Status: IN_PROGRESS

## 2026-06-11 13:50:00 Europe/Paris

ORCHESTRATOR: Full codebase analysis complete. Root cause identified: `runClarifyHelpRequest()` bypasses `SupervisionProvider` interface with direct OpenAI Responses API call. Solution designed: add `runScenario()` method to interface, implement in all three providers, refactor controller. SMT instructions ready for CODEUR.

## 2026-06-11 13:58:00 Europe/Paris

ORCHESTRATOR: SMT conversation file created for CODEUR at `ai-local/conversations/20260611-13h55-TASK-246-clarify-provider-agnostic.md`. Instructions include architecture decision, impacted files, implementation constraints, required tests, UI validation, forbidden changes, and acceptance criteria.

# Handoffs

# Tests

- [ ] Feature tests for clarify_help_request with Ollama
- [ ] Feature tests for clarify_help_request with OpenRouter
- [ ] Unit tests for OllamaSupervisionProvider::runScenario()
- [ ] Unit tests for OpenRouterSupervisionProvider::runScenario()
- [ ] Browser validation at /admin/ai-supervision
- [ ] Remove test_clarify_help_request_is_not_supported_with_ollama
- [ ] Remove test_clarify_help_request_is_not_supported_with_openrouter

---
# Test Results

Pending.

---
# Review Notes

Pending.

---
# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
