---
task_id: TASK-243
title: Disable OpenAI in admin AI lab by default and configure Ollama local

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-243-disable-openai-in-admin-ai-lab-by-default-and-configure-ollama-local

priority: MEDIUM

created_at: 2026-06-11 06:46:39 Europe/Paris
updated_at: 2026-06-11 06:55:00 Europe/Paris

labels: [ai, supervision, provider, ollama-first]

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-11 06:55:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Prevent accidental OpenAI usage in `/admin/ai-supervision`. Make Ollama local the priority provider. OpenAI now requires explicit opt-in via `OPENAI_SUPERVISION_ENABLED=true`.

---

# Planned Actions

- [x] Add `OPENAI_SUPERVISION_ENABLED` config to `config/ai.php` (default: false)
- [x] Modify `SupervisionProviderResolver`: gate openai behind `supervision_enabled`, no silent fallback, null default when no providers active
- [x] Modify controller: hide `clarify_help_request` when OpenAI supervision disabled, redirect with error on POST attempt, handle empty providers
- [x] Modify UI: "Aucun provider IA actif" state, "Cloud uniquement" banner, disabled form when no providers
- [x] Update `.env.example`: `OPENAI_SUPERVISION_ENABLED=false`, `OLLAMA_ENABLED=true` recommendation, OpenRouter default model changed
- [x] Create `ai/scripts/ai-local-provider-check.sh`
- [x] Update tests: 39 tests, 135 assertions green
- [x] Browser validation

---

# Progress Log

## 2026-06-11 06:46:39 Europe/Paris

Task created.

## 2026-06-11 06:50-06:55 Europe/Paris

Implementation complete:

- `config/ai.php`: Added `supervision_enabled` key (default false) under `openai`, changed OpenRouter default model to `deepseek/deepseek-chat-v3-0324`
- `SupervisionProviderResolver`: `defaultProvider()` returns `?string` (null when no provider active), `availableProviders()` gates OpenAI behind `supervision_enabled`, added `type` field to each provider entry
- `AdminAiSupervisionController`: `index()` passes `hasActiveProvider`, hides `clarify_help_request` when OpenAI supervision disabled; `analyze()` returns `View|RedirectResponse`, blocks POST with `clarify_help_request` when OpenAI disabled, blocks POST when no active providers
- `index.blade.php`: "Aucun provider IA actif" banner with .env instructions, "Cloud uniquement" banner when only OpenAI enabled, disabled form when no providers, no "Fallback" wording
- `.env.example`: Added `OPENAI_SUPERVISION_ENABLED=false`, changed `OLLAMA_ENABLED=true`, changed OpenRouter default model, added comments explaining opt-in
- `ai/scripts/ai-local-provider-check.sh`: Non-secret provider status checker
- Tests: 39 tests, 135 assertions — all green

---

# Handoffs

None.

# Tests

- [x] feature tests (39 tests, 135 assertions green)
- [x] browser validation (no-provider state, no OpenAI, "Aucun provider IA actif" banner)
- [ ] responsive validation
- [ ] console inspection (1 JS error unrelated to this task)
- [ ] tenant validation

---

# Test Results

39 tests, 135 assertions — all green.

New tests:
- test_openai_not_shown_when_supervision_disabled
- test_no_active_provider_shows_message
- test_ollama_is_first_provider_when_enabled
- test_clarify_help_request_hidden_when_openai_supervision_disabled
- test_clarify_help_request_visible_when_openai_supervision_enabled
- test_analyze_rejects_clarify_when_openai_supervision_disabled
- test_openrouter_visible_when_enabled
- test_default_provider_is_openai_when_ollama_disabled_but_openai_supervision_enabled
- test_cloud_only_banner_shown_when_only_openai_enabled
- test_no_cloud_only_banner_when_ollama_enabled

Updated existing tests:
- setUp now sets `ai.openai.supervision_enabled => true`, `ai.ollama.enabled => false`, `ai.openrouter.enabled => false`
- All clarify_help_request tests explicitly set `openai.supervision_enabled => true`
- Fallback banner renamed to "Cloud uniquement"

---

# Review Notes

Key architectural changes:
1. OpenAI is no longer a default/fallback provider. It must be explicitly enabled.
2. `defaultProvider()` now returns `?string` — null means no active provider.
3. `clarify_help_request` scenario is hidden from UI when OpenAI supervision is disabled.
4. OpenRouter default model changed from `openai/gpt-4o-mini` to `deepseek/deepseek-chat-v3-0324`.
5. No DB changes. No migrations. No .env file modifications (only .env.example updated).

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`