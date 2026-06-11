---
task_id: TASK-246
title: Make clarify_help_request provider-agnostic for Ollama and OpenRouter

status: IN_REVIEW

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-246-make-clarify-help-request-provider-agnostic-for-ollama-and-openrouter

priority: HIGH

created_at: 2026-06-11 13:46:01 Europe/Paris
updated_at: 2026-06-11 14:24:25 Europe/Paris

labels:
  - ai
  - refactor
  - provider-agnostic
  - clarify_help_request

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: READY_FOR_VERIFICATION
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

- [x] 1. Add `runScenario()` method to `SupervisionProvider` interface
- [x] 2. Implement `runScenario()` in `OllamaSupervisionProvider` (uses `/api/generate` + `format: 'json'` + `think: false` + `JsonResponseParser`)
- [x] 3. Implement `runScenario()` in `OpenRouterSupervisionProvider` (uses `/chat/completions` + `response_format: json_schema` + `JsonResponseParser`)
- [x] 4. Implement `runScenario()` in `OpenAiSupervisionProvider` (refactored from current `runClarifyHelpRequest()` logic, uses `/responses` + `text.format.json_schema strict`)
- [x] 5. Replace `runClarifyHelpRequest()` in controller with `$provider->runScenario($scenario, $content, $model)`
- [x] 6. Update `SupervisionProviderResolver::supportedScenarios()` — `clarify_help_request` available for all providers
- [x] 7. Update `ClarifyHelpRequestScenario::providerHint()` — return empty string or `null` (no provider preference)
- [x] 8. Remove OpenAI-only guards in controller (lines 44-47, 85-89, 101-108)
- [x] 9. Remove `test_clarify_help_request_is_not_supported_with_ollama` and `test_clarify_help_request_is_not_supported_with_openrouter` (no longer true)
- [x] 10. Add new tests: `test_clarify_help_request_works_with_ollama`, `test_clarify_help_request_works_with_openrouter`
- [x] 11. Update Blade template scenario compatibility (no Blade edit needed; scenario compatibility is resolver-driven and existing rendering already supports array results)
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

## 2026-06-11 14:02:00 Europe/Paris

ORCHESTRATOR: Post TASK-245 checkpoint confirmed. Git worktree clean before new edits, current branch `TASK-246-make-clarify-help-request-provider-agnostic-for-ollama-and-openrouter`, `develop` and `origin/develop` both at `bb1b22e`. Runtime validation `/admin/ai-supervision` with provider Ollama, model `qwen3.5:latest`, scenario `supervision_content`, input `Je fais une demande de devis pour un logo`: SUCCESS. Structured result rendered with risk low, category `creer-des-supports`, latency 11422 ms, no `Sortie JSON non décodable`. Planning file `ai-local/PLANNING_TRAVAUX_MODULES_IA_T244àT265.md` read; user instruction renumbers provider-agnostic clarify work as TASK-246. Active conversation updated with mandatory CODEUR/VERIFICATOR reading list and agent protocol.

## 2026-06-11 14:10:00 Europe/Paris

ORCHESTRATOR: Controlled recovery after suspected CODEUR test blockage. Checked running processes with `ps -ef | grep -E "php artisan test|vendor/bin/phpunit|phpunit" | grep -v grep || true`; no PHPUnit/Laravel test process was active, so no Escape interruption was sent. Sent SMT QUESTION to CODEUR asking for exact TASK-246 state, to stop long tests, to never relaunch `migrate:fresh`, and to finalize report/tests/TASK/conversation/commit if clean. Documented incident: CODEUR reportedly launched `php artisan migrate:fresh` on `bouclepro_test`; even if limited to the test DB, this is destructive and must not be repeated without explicit Cyril/Cockpit validation.

Blocker/risk note: Awaiting CODEUR report confirming patch state, modified files, tests, git status, and confirmation that `migrate:fresh` affected only `bouclepro_test`.

## 2026-06-11 14:24:25 Europe/Paris

CODEUR: Implementation complete and ready for read-only verification. Added provider-agnostic `SupervisionProvider::runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array` and implemented it in `OllamaSupervisionProvider`, `OpenRouterSupervisionProvider`, `OpenAiSupervisionProvider`, and `LoggingSupervisionProvider`. Refactored `AdminAiSupervisionController` so `clarify_help_request` resolves the selected provider and calls `runScenario()` instead of making a direct OpenAI `/responses` call. Removed OpenAI-only visibility/POST/provider guards. Updated `SupervisionProviderResolver::supportedScenarios()` to expose `clarify_help_request` for all providers and changed `ClarifyHelpRequestScenario::providerHint()` to return an empty string.

CODEUR: Test coverage updated. Replaced old negative Ollama/OpenRouter clarify tests with positive feature tests for `clarify_help_request` via HTTP fakes. Added provider unit coverage for Ollama `/api/generate` payload (`format: json`, `think: false`, schema in prompt) and OpenRouter `/chat/completions` payload (`response_format.json_schema`, strict schema). Ran `./vendor/bin/pint --dirty` after implementation and re-ran the targeted tests sequentially.

CODEUR: DB safety confirmation. Testing configuration was checked before test execution and resolved to `database.default = pgsql` and `database.connections.pgsql.database = bouclepro_test`. The earlier `migrate:fresh` incident was limited to the testing command context using `DB_DATABASE=bouclepro_test`; no runtime `bouclepro` database command was run by this finalization pass. No `migrate:fresh`, `db:wipe`, reset DB, or other destructive DB command will be relaunched for TASK-246.

CODEUR: UI runtime validation with real provider call was not repeated during this finalization pass because ORCH explicitly requested stopping long tests and using targeted sequential validation only. Feature coverage confirms visibility and execution paths with Ollama/OpenRouter fakes; runtime Ollama `supervision_content` had already passed at 14:02 before this implementation.

# Handoffs

- 2026-06-11 14:24:25 Europe/Paris — CODEUR implementation complete. Branch ready for ORCH/VERIFICATOR read-only review. Lock released. Do not run long tests, parallel tests, `migrate:fresh`, `db:wipe`, DB resets, or TASK-247 from this handoff.

# Tests

- [x] Feature tests for clarify_help_request with Ollama
- [x] Feature tests for clarify_help_request with OpenRouter
- [x] Unit tests for OllamaSupervisionProvider::runScenario()
- [x] Unit tests for OpenRouterSupervisionProvider::runScenario()
- [ ] Browser validation at /admin/ai-supervision
- [x] Remove test_clarify_help_request_is_not_supported_with_ollama
- [x] Remove test_clarify_help_request_is_not_supported_with_openrouter

---
# Test Results

- 2026-06-11 14:02 Europe/Paris — Runtime checkpoint PASS: `/admin/ai-supervision`, Ollama, `qwen3.5:latest`, `supervision_content`, input `Je fais une demande de devis pour un logo`, structured result rendered, no JSON decode error.
- 2026-06-11 14:24 Europe/Paris — DB test preflight PASS: `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default` returned `pgsql`; `php artisan config:show database.connections.pgsql.database` returned `bouclepro_test`.
- 2026-06-11 14:24 Europe/Paris — PASS: `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=AdminAiSupervisionTest` — 43 passed, 148 assertions.
- 2026-06-11 14:24 Europe/Paris — PASS: `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=OllamaSupervisionProviderTest` — 8 passed, 35 assertions.
- 2026-06-11 14:24 Europe/Paris — PASS: `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=OpenRouterSupervisionProviderTest` — 10 passed, 41 assertions.
- 2026-06-11 14:24 Europe/Paris — PASS: `APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test --filter=AiScenarioFactoryTest` — 10 passed, 44 assertions.
- 2026-06-11 14:24 Europe/Paris — PASS: `./vendor/bin/pint --dirty` completed and fixed formatting on modified PHP files; targeted tests above were re-run after formatting and stayed green.

---
# Review Notes

- 2026-06-11 14:10 Europe/Paris — Incident to verify: `migrate:fresh` reportedly executed by CODEUR on `bouclepro_test`. Must be documented in final verification. No destructive DB command may be relaunched. VERIFICATOR must specifically check `LoggingSupervisionProvider::runScenario()`, no DB/migration additions, no raw content in JSONL, OpenAI disabled by default, HTTP fake coverage for Ollama/OpenRouter, and runtime Ollama clarify validation if possible.
- 2026-06-11 14:24 Europe/Paris — CODEUR confirmation: finalization pass did not run any destructive DB command. The earlier `migrate:fresh` incident was in the explicit testing environment targeting `bouclepro_test`; no command targeting runtime `bouclepro` was run in this finalization pass. No further destructive DB command will be relaunched for TASK-246. Remaining review item: VERIFICATOR read-only check before finalize/merge.

---
# Modified Files

- `app/Http/Controllers/Admin/AdminAiSupervisionController.php`
- `app/Services/Ai/Contracts/SupervisionProvider.php`
- `app/Services/Ai/Providers/LoggingSupervisionProvider.php`
- `app/Services/Ai/Providers/OllamaSupervisionProvider.php`
- `app/Services/Ai/Providers/OpenAiSupervisionProvider.php`
- `app/Services/Ai/Providers/OpenRouterSupervisionProvider.php`
- `app/Services/Ai/Scenarios/ClarifyHelpRequestScenario.php`
- `app/Services/Ai/SupervisionProviderResolver.php`
- `tests/Feature/Admin/AdminAiSupervisionTest.php`
- `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php`
- `tests/Unit/Services/Ai/OpenRouterSupervisionProviderTest.php`
- `TODO/TASK-246-make-clarify-help-request-provider-agnostic-for-ollama-and-openrouter.md`
- `ai-local/conversations/20260611-13h55-TASK-246-clarify-provider-agnostic.md`

---
# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
