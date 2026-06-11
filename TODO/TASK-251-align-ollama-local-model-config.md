---
task_id: TASK-251
title: Align Ollama local model config with tested Ministral model

status: IN_PROGRESS

owner: OPENCODE

contributors:
  - CODEUR

branch: TASK-251-align-ollama-local-model-config

priority: HIGH

created_at: 2026-06-11 17:35:00 Europe/Paris
updated_at: 2026-06-11 17:35:00 Europe/Paris

labels:
  - ai
  - admin
  - config
  - ollama

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-11 17:35:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Update Ollama default model configuration from `llama3.2` to `ministral-3:3b`
across config, env example, view recommendation, and provider resolver fallback.
No new provider logic, no migration, no prod changes.

---

# Context

- GPU RTX 4070 12 Go, Ollama 0.23.1 via Windows NVIDIA driver (WSL validé)
- Modèle local retenu pour clarification BouclePro : `ministral-3:3b`
- Modèle qualitatif comparaison : `mistral:7b`
- Modèle code/JSON technique : `qwen2.5-coder:7b`
- `qwen3.5:latest` non satisfaisant (thinking mode problématique)
- Prompt validé : JSON schema strict, temperature 0, num_ctx 2048, num_predict 384
- Le `.env` local a déjà `OLLAMA_MODEL=ministral-3:3b`, le runtime fonctionne

---

# Strict Scope

## Included

1. `config/ai.php` — default `ai.ollama.model`: `llama3.2` → `ministral-3:3b`
2. `.env.example` — `OLLAMA_MODEL=llama3.2` → `OLLAMA_MODEL=ministral-3:3b`
3. `resources/views/admin/ai-supervision/index.blade.php` — recommandation visible: `OLLAMA_MODEL=votre_modèle` → `OLLAMA_MODEL=ministral-3:3b`
4. `app/Services/Ai/SupervisionProviderResolver.php` — fallback hardcodé `'llama3.2'` → `'ministral-3:3b'` (lignes 65 et 113)
5. Régression `AdminAiSupervisionTest` — doit rester green

## Excluded (strictly forbidden)

- ❌ Nouvelle logique provider
- ❌ Changement provider agnostic
- ❌ Modification des providers (Ollama/OpenRouter/OpenAI)
- ❌ Migration DB, schema
- ❌ Destructive DB (`migrate:fresh`, `db:wipe`)
- ❌ Modification prod
- ❌ Nouveau prompt IA
- ❌ Hardcodage modèle dans la logique métier

---

# Acceptance Criteria

1. `config('ai.ollama.model')` retourne `ministral-3:3b` sans `.env`
2. `.env.example` montre `OLLAMA_MODEL=ministral-3:3b`
3. Vue `/admin/ai-supervision` (section provider inactif) recommande `ministral-3:3b`
4. `SupervisionProviderResolver` fallback cohérent avec `ministral-3:3b`
5. Aucune régression `AdminAiSupervisionTest` — 100% green
6. Préflight DB `bouclepro_test` OK avant tout test

---

# Risks

- Ne pas casser compatibilité multi-provider (OpenAI, OpenRouter, Ollama)
- Ne pas hardcoder un modèle spécifique dans la logique provider-agnostique
- Les tests qui référencent `qwen3.5` dans `OllamaSupervisionProviderTest` ne doivent PAS être modifiés

---

# Files

## Modified files
```
config/ai.php
.env.example
resources/views/admin/ai-supervision/index.blade.php
app/Services/Ai/SupervisionProviderResolver.php
```

## Test regression only (no new test file)
```
tests/Feature/Admin/AdminAiSupervisionTest.php
```

---

# Planned Actions

- [ ] read mandatory docs (AGENTS.md, SMT skill, ai-local/README, tooling)
- [ ] inspect current config, env.example, view, resolver
- [ ] modify `config/ai.php` — default model
- [ ] modify `.env.example` — default model
- [ ] modify `views/admin/ai-supervision/index.blade.php` — recommendation
- [ ] modify `SupervisionProviderResolver.php` — fallback
- [ ] run DB-safe preflight `bouclepro_test`
- [ ] run regression `AdminAiSupervisionTest`
- [ ] update TASK and conversation
- [ ] handoff to VERIFICATOR

---

# Progress Log

## 2026-06-11 17:35:00 Europe/Paris

Task created. Branch `TASK-251-align-ollama-local-model-config`.

## 2026-06-11 17:38:00 Europe/Paris

ORCH created conversation file, committed TASK+conversation, pushed branch, sent SMT to CODEUR.
Awaiting CODEUR DONE.

---

# Handoffs

## 2026-06-11 17:35:00 Europe/Paris — ORCH to CODEUR

SMT sent via tmux with full scope instructions. Conversation:
`ai-local/conversations/20260611-17h35-TASK-251-align-ollama-local-model-config.md`

---

# Tests

- [ ] regression: AdminAiSupervisionTest (48 tests, 187 assertions)

---

# Test Results

TODO — after CODEUR execution.

---

# Review Notes

- VERIFICATOR must confirm scope strict: no provider logic, no migration, no destructive DB.
- VERIFICATOR must confirm ONLY the 4 specified files were modified.
- VERIFICATOR must confirm `AdminAiSupervisionTest` regression green.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
