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
updated_at: 2026-06-11 15:10:00 Europe/Paris

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

---

# Handoffs

CODEUR implementation launched via SMT at 2026-06-11 15:10 Europe/Paris.

---

# Tests

- [ ] DB-safe preflight
- [ ] migration/model tests
- [ ] admin AI persistence feature tests
- [ ] targeted AI regression tests if touched
- [ ] runtime validation with Ollama + `qwen2.5-coder:7b`

---

# Test Results

Pending.

---

# Review Notes

- VERIFICATOR must verify additive migration only.
- VERIFICATOR must verify no `.env` / `config/ai.php` / provider behavior changes.
- VERIFICATOR must verify no raw provider response, system prompt, secret, or API key is persisted.
- VERIFICATOR must verify DB write failure cannot break successful AI responses.
- VERIFICATOR must verify no destructive DB command was used.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
