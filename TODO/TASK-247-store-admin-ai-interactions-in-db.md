---
task_id: TASK-247
title: Store admin AI interactions in DB

status: IN_PROGRESS

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-247-store-admin-ai-interactions-in-db

priority: HIGH

created_at: 2026-06-11 15:03:12 Europe/Paris
updated_at: 2026-06-11 15:48:00 Europe/Paris

labels:
  - ai
  - database
  - admin
  - audit
  - additive-migration

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-11 15:03:12 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Add an additive database persistence layer for admin AI lab interactions.

This task must persist metadata and sanitized result data for admin AI runs already executed through the existing provider architecture. It must not introduce a provider, alter provider configuration, or build the full history UI.

Default local runtime model for validation is `qwen2.5-coder:7b` because `qwen3.5:latest` is too slow for this task.

---

# Scope

## In Scope

- Add one additive migration for an `admin_ai_interactions` table or equivalent clearly named table.
- Add an Eloquent model for persisted admin AI interactions.
- Persist successful admin AI interactions from the existing logging/decorator path.
- Persist both current scenarios:
  - `supervision_content`
  - `clarify_help_request`
- Persist provider/model/scenario/status/latency/token/cost metadata where available.
- Persist sanitized structured result data needed for later admin history and cost dashboards.
- Preserve the existing JSONL benchmark logger unless a minimal adapter makes dual-write cleaner.
- Add focused tests for DB persistence.

## Out of Scope

- No new AI provider.
- No provider config changes in `config/ai.php` or `.env`.
- No default OpenAI reactivation.
- No prompt/schema/provider behavior changes.
- No admin history UI in this task.
- No cost dashboard UI in this task.
- No ChatLoop, annuaire, user-facing AI flow, or help-request publication changes.
- No destructive DB operation.

---

# Required Design Constraints

- Migration must be additive only: `Schema::create(...)` is allowed; dropping/changing existing tables is forbidden.
- PostgreSQL-compatible column types only.
- Organization scoping must be considered. Prefer nullable `organization_id` with `nullOnDelete()` if the admin route can run without a resolved organization.
- Prefer nullable `user_id` for the admin user executing the interaction.
- Do not persist system prompts, provider secrets, API keys, or raw provider responses.
- Avoid storing full raw user content unless explicitly needed; prefer `input_excerpt`, `input_hash`, and `input_length` for audit-safe traceability.
- Store structured AI output/result in JSON with sensitive/raw-provider fields stripped.
- Use the existing provider/decorator architecture instead of adding controller-specific DB writes when feasible.
- DB write failures must not break the AI response; log a warning and continue, similar to `AiBenchmarkLogger` behavior.

---

# Suggested Table Shape

CODEUR may adjust names for Laravel consistency, but must preserve the intent:

- `id` UUID primary key
- `organization_id` nullable UUID FK to `organizations`
- `user_id` nullable UUID FK to `users`
- `scenario_id` string indexed
- `provider` string nullable/indexed
- `model` string nullable/indexed
- `status` string default `success`, indexed
- `input_excerpt` text nullable, capped/sanitized before persistence
- `input_hash` string nullable/indexed
- `input_length` unsigned integer default 0
- `result_summary` text nullable
- `result_payload` json nullable, sanitized structured output only
- `metadata` json nullable for non-sensitive telemetry
- `input_tokens` unsigned integer default 0
- `output_tokens` unsigned integer default 0
- `latency_ms` decimal or unsigned integer nullable
- `cost_usd` decimal with sufficient precision default 0
- `created_at`, `updated_at`

Indexes should support future admin history filtering by scenario/provider/model/status/created_at and tenant/user where available.

---

# Impacted Files

Expected, not exhaustive:

- `database/migrations/*_create_admin_ai_interactions_table.php`
- `app/Models/AdminAiInteraction.php`
- `app/Services/Ai/Providers/LoggingSupervisionProvider.php`
- `app/Services/Ai/Logging/AiBenchmarkLogger.php` or a new small persistence service if cleaner
- `app/Providers/AppServiceProvider.php`
- `tests/Feature/Admin/AdminAiSupervisionTest.php`
- `tests/Unit/Services/Ai/*` as needed

---

# Planned Actions

- [x] Checkpoint `develop` clean and synced before branch creation
- [x] Read planning/tooling docs
- [x] Create TASK-247 branch and TASK file
- [x] Prepare conversation file and SMT for CODEUR
- [ ] CODEUR reads mandatory docs and implements additive DB persistence
- [ ] CODEUR runs DB-safe targeted tests only
- [ ] VERIFICATOR performs read-only verification
- [ ] ORCH finalizes and merges only after VERIFICATOR OK

---

# Test Expectations

Before Laravel tests, verify test DB safety:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database
```

Expected:

```text
database.default = pgsql
database.connections.pgsql.database = bouclepro_test
```

Allowed targeted tests:

- `AdminAiSupervisionTest` persistence assertions for `supervision_content`
- `AdminAiSupervisionTest` persistence assertions for `clarify_help_request`
- Unit tests for any new persistence service/model sanitization
- Existing AI provider unit tests only if touched

Forbidden test/runtime commands:

- `php artisan migrate:fresh`
- `php artisan db:wipe`
- parallel tests
- any test command resolving to `DB_DATABASE=bouclepro`

---

# Runtime Validation

After implementation and tests:

- Use `/admin/ai-supervision` with provider `Ollama (local)` and model `qwen2.5-coder:7b`.
- Run `supervision_content` with sample input `Je fais une demande de devis pour un logo`.
- Confirm structured UI output still renders.
- Confirm one DB row is created for the interaction.
- Do not download new models.
- If `qwen2.5-coder:7b` is unavailable, stop and report instead of changing config/downloading models.

---

# Forbidden

- No `.env` changes.
- No `config/ai.php` changes.
- No new model downloads.
- No destructive DB command.
- No migrations altering/dropping existing tables.
- No provider behavior changes.
- No prompt/schema changes.
- No OpenAI default reactivation.
- No broad refactor.
- No direct work on `main` or production.

---

# Progress Log

## 2026-06-11 15:03:12 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-247-store-admin-ai-interactions-in-db

Status:
IN_PROGRESS

## 2026-06-11 15:08:00 Europe/Paris

ORCH checkpoint completed before launch:

- Worktree clean.
- Current branch before creation: `develop`.
- `develop` and `origin/develop` synced at `73b9d11ed5c62b748bcca2b3e38c6db0a905abeb`.
- TASK-247 branch created from clean `develop`.
- Planning read: internal roadmap listed DB storage as old TASK-246, but Cockpit directive assigns this work to TASK-247 after provider-agnostic clarify consumed TASK-246.
- Scope fixed as additive DB persistence only.
- Runtime validation model set to `qwen2.5-coder:7b` per Cockpit directive.

## 2026-06-11 15:10:00 Europe/Paris

Cockpit/Cyril confirmed `qwen2.5-coder:7b` for runtime validation. ORCH sent CODEUR SMT:

`[2026-06-11 15:10][TASK-247][branch:TASK-247-store-admin-ai-interactions-in-db][ORCH→CODEUR][ACTION] Lire AGENTS.md, tmux SMT skill, ai-local/README.md, ai/tooling docs, TASK file et ai-local/conversations/20260611-15h10-TASK-247-admin-ai-interactions-db.md. Implémenter uniquement persistance DB additive admin AI interactions. Pas de config/provider, pas de DB destructive, modèle runtime qwen2.5-coder:7b. Répondre DONE dans la conversation.`

Next state: waiting for CODEUR DONE report in the active conversation.

## 2026-06-11 15:18:00 Europe/Paris

CODEUR role clarification and ACK sequence documented:

- Cockpit reported role confusion in the target tmux session.
- ORCH sent clarification SMT to `codeur`, explicitly assigning TASK-247 CODEUR role and asking implementation to start now.
- CODEUR acknowledged: reading files and implementation in progress.

Next state: wait for CODEUR DONE report in `ai-local/conversations/20260611-15h10-TASK-247-admin-ai-interactions-db.md`.

## 2026-06-11 15:20:00 Europe/Paris

Cyril clarified that VERIFICATOR mentions and old pane titles in tmux session `codeur` are stale metadata from a previous task. ORCH sent an additional SMT to `codeur` asking the agent to ignore those vestiges, assume CODEUR role for TASK-247, and start/continue implementation now.

Next state: wait for CODEUR DONE report in the active conversation.

## 2026-06-11 15:26:00 Europe/Paris

CODEUR implementation DONE report:

- Migration `2026_06_11_150000_create_admin_ai_interactions_table.php` created and migrated on test DB.
- Model `AdminAiInteraction` created with UUID PK, fillable, casts, and BelongsTo relations.
- Persistence service `AdminAiInteractionPersistence` created with safe-excerpt, hash, payload/metadata sanitization, organization/user resolution, and failure-isolation (try/catch + warning log).
- `LoggingSupervisionProvider` refactored to inject `AdminAiInteractionPersistence` and call `persist()` in both `supervise()` and `runScenario()`.
- `AppServiceProvider` updated to register `AdminAiInteractionPersistence` as singleton and inject it into `LoggingSupervisionProvider`.
- Existing `AiBenchmarkLogger` JSONL logging preserved; dual-write pattern used.
- `tests/Feature/Admin/AdminAiSupervisionTest.php` updated with 3 new persistence tests:
  - `test_supervision_content_persists_admin_ai_interaction`
  - `test_clarify_help_request_persists_admin_ai_interaction`
  - `test_persistence_does_not_store_api_keys_or_secrets`
- `./vendor/bin/pint --dirty` run; style fixed.
- Targeted tests run and pass:
  - 3 new persistence tests: 3 passed (26 assertions)
  - 5 existing regression tests: 5 passed (19 assertions)
- No `.env` / `config/ai.php` changes.
- No provider behavior/prompt/schema changes.
- No destructive DB command used.
- Runtime validation with Ollama + `qwen2.5-coder:7b` pending (CODEUR environment has no Ollama runtime).

## 2026-06-11 15:34:00 Europe/Paris

ORCH launched VERIFICATOR read-only review after CODEUR DONE:

- Correct current tmux mapping confirmed: `orchestrator`, `codeur`, `verificator`.
- ORCH sent VERIFICATOR SMT to `verificator` at 15:32.
- Pane capture confirmed VERIFICATOR is actively reviewing TASK-247, checking persistence behavior, tests, `basePayload`, and fake OpenAI response details before verdict.

Next state: wait for VERIFICATOR verdict in active conversation.

## 2026-06-11 15:36:00 Europe/Paris

VERIFICATOR returned BLOCKED verdict: 8/10 OK, 2 blocking gaps:

- Ollama and OpenRouter are returned raw by `SupervisionProviderResolver::resolve()`, bypassing `LoggingSupervisionProvider`; therefore no DB persistence happens outside OpenAI.
- `admin_ai_interactions.provider` remains `NULL` because `LoggingSupervisionProvider` does not know/pass the provider name.

VERIFICATOR reported 46 tests passing (174 assertions), DB-safe, migration additive, no config/provider behavior changes, sanitization/failure isolation OK. Runtime Ollama with `qwen2.5-coder:7b` is available locally but would not create rows until wrapper gap is fixed.

## 2026-06-11 15:38:00 Europe/Paris

ORCH sent CODEUR targeted fix SMT:

`[2026-06-11 15:38][TASK-247][branch:TASK-247-store-admin-ai-interactions-in-db][ORCH→CODEUR][ACTION] VERIFICATOR BLOCKED 8/10. Fix ciblé requis: (1) wrapper Ollama/OpenRouter dans SupervisionProviderResolver avec LoggingSupervisionProvider, (2) ajouter providerName au LoggingSupervisionProvider, (3) passer openai/ollama/openrouter, (4) persister provider non NULL, (5) tests + runtime Ollama qwen2.5-coder:7b si possible. Lire Entry 9 conversation. Pas de scope creep, pas DB destructive. Répondre DONE.`

Next state: wait for CODEUR DONE after blocking fixes.

## 2026-06-11 15:46:00 Europe/Paris

CODEUR blocking fix DONE report:

### Fixes applied

1. **`LoggingSupervisionProvider`** — added `string $providerName` constructor parameter (default `'unknown'`); passed as `'provider'` in both `persist()` calls.
2. **`SupervisionProviderResolver::resolve()`** — Ollama and OpenRouter now wrapped with `LoggingSupervisionProvider` via new private `wrapWithLogging()` method. OpenAI remains resolved via singleton `SupervisionProvider::class` (already wrapped in `AppServiceProvider`).
3. **`AppServiceProvider`** — OpenAI `LoggingSupervisionProvider` instantiation now passes `'openai'` as 4th argument.
4. **Tests updated** — existing 3 persistence tests now assert `provider === 'openai'`; 2 new tests added:
   - `test_ollama_supervision_content_persists_with_provider_name`
   - `test_openrouter_clarify_help_request_persists_with_provider_name`
5. **`./vendor/bin/pint --dirty`** — passed (4 files).

### Test results after fix

- Full `AdminAiSupervisionTest` suite: **48 passed, 187 assertions, 3.29s**
- New persistence tests (5 passed, 37 assertions):
  - `test_supervision_content_persists_admin_ai_interaction` — provider `openai` asserted
  - `test_clarify_help_request_persists_admin_ai_interaction` — provider `openai` asserted
  - `test_persistence_does_not_store_api_keys_or_secrets`
  - `test_ollama_supervision_content_persists_with_provider_name` — provider `ollama` asserted
  - `test_openrouter_clarify_help_request_persists_with_provider_name` — provider `openrouter` asserted
- Regression tests (43 passed, 150 assertions) — all green, no breakage.

### Runtime validation

- Ollama runtime + `qwen2.5-coder:7b` not available in CODEUR environment; pending ORCH/VERIFICATOR validation.

### Forbidden items verified absent

- No `.env` changes.
- No `config/ai.php` changes.
- No new provider.
- No prompt/schema/provider behavior changes.
- No destructive DB command.
- No OpenAI default reactivation.

## 2026-06-11 15:48:00 Europe/Paris

ORCH launched VERIFICATOR read-only re-review for CODEUR fix commit `6ab9939`:

`[2026-06-11 15:48][TASK-247][branch:TASK-247-store-admin-ai-interactions-in-db][ORCH→VERIF][REVIEW] CODEUR DONE fix commit 6ab9939. Revue read-only ciblée des 2 blockers: Ollama/OpenRouter doivent être wrappés par LoggingSupervisionProvider, provider doit être non NULL pour openai/ollama/openrouter. Vérifier aussi tests 48/187, pas config/env/provider behavior, pas DB destructive. Runtime Ollama qwen2.5-coder:7b si dispo sans download/config. Verdict dans conversation/TASK.`

Next state: wait for VERIFICATOR verdict after re-review.

---

# Handoffs

CODEUR implementation launched via SMT at 2026-06-11 15:10 Europe/Paris.

---

# Tests

- [x] DB-safe preflight
- [x] migration/model tests
- [x] admin AI persistence feature tests
- [x] targeted AI regression tests if touched
- [ ] runtime validation with Ollama + `qwen2.5-coder:7b`

---

# Test Results

2026-06-11 15:26 Europe/Paris

- DB-safe preflight: `database.default = pgsql`, `database.connections.pgsql.database = bouclepro_test` — confirmed safe.
- New persistence tests (3 passed, 26 assertions):
  - `test_supervision_content_persists_admin_ai_interaction` — 1.21s
  - `test_clarify_help_request_persists_admin_ai_interaction` — 0.05s
  - `test_persistence_does_not_store_api_keys_or_secrets` — 0.05s
- Regression tests (5 passed, 19 assertions):
  - `test_admin_can_analyze_content_with_mocked_openai_response` — 1.21s
  - `test_admin_can_use_clarify_help_request_scenario` — 0.04s
  - `test_clarify_help_request_works_with_ollama` — 0.04s
  - `test_supervision_content_with_ollama_provider` — 0.05s
  - `test_supervision_content_with_openrouter_provider` — 0.04s
- Runtime validation: pending (CODEUR environment has no Ollama runtime).

2026-06-11 15:46 Europe/Paris (after VERIFICATOR blocking fix)

- Full `AdminAiSupervisionTest` suite: **48 passed, 187 assertions, 3.29s**
- New persistence tests (5 passed, 37 assertions):
  - `test_supervision_content_persists_admin_ai_interaction` — provider `openai` asserted
  - `test_clarify_help_request_persists_admin_ai_interaction` — provider `openai` asserted
  - `test_persistence_does_not_store_api_keys_or_secrets`
  - `test_ollama_supervision_content_persists_with_provider_name` — provider `ollama` asserted
  - `test_openrouter_clarify_help_request_persists_with_provider_name` — provider `openrouter` asserted
- Regression tests: all 43 remaining tests pass (150 assertions), no breakage.
- Runtime validation: pending (CODEUR environment has no Ollama runtime).

---

# Review Notes

- VERIFICATOR must verify additive migration only.
- VERIFICATOR must verify no `.env` / `config/ai.php` / provider behavior changes.
- VERIFICATOR must verify no raw provider response, system prompt, secret, or API key is persisted.
- VERIFICATOR must verify DB write failure cannot break successful AI responses.
- VERIFICATOR must verify no destructive DB command was used.
- VERIFICATOR must verify all providers (openai/ollama/openrouter) are wrapped with `LoggingSupervisionProvider` and `provider` column is non-null.
- Previous VERIFICATOR verdict (15:36): BLOCKED 8/10 → gaps fixed at 15:46.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
