---
task_id: TASK-239
title: OpenRouter provider for admin AI lab

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-239-openrouter-provider-for-admin-ai-lab

priority: MEDIUM

created_at: 2026-06-11 00:26:37 Europe/Paris
updated_at: 2026-06-11 00:50:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 00:50:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter un provider OpenRouter dans le lab IA admin. OpenRouter est un proxy API qui expose des centaines de modèles via une API OpenAI-compatible (Chat Completions). Provider standalone, pas le provider par défaut, accessible uniquement via le lab admin.

---

# Planned Actions

- [ ] Créer `app/Services/Ai/Providers/OpenRouterSupervisionProvider.php` — implémente `SupervisionProvider`, call Chat Completions API
- [ ] Ajouter section `openrouter` à `config/ai.php`
- [ ] Enregistrer comme singleton dans `AppServiceProvider` (`openrouter` key)
- [ ] Ajouter vars à `.env.example`
- [ ] Ajouter `openrouter` dans `AVAILABLE_MODELS` du controller
- [ ] Créer `tests/Unit/Services/Ai/OpenRouterSupervisionProviderTest.php` (6 tests HTTP fake)
- [ ] Vérifier non-régression (tests existants)

---

# Architecture

## OpenRouterSupervisionProvider

- **Interface**: `SupervisionProvider`
- **Endpoint**: `POST {base_url}/chat/completions` (Chat Completions, pas Responses API)
- **Auth**: `Authorization: Bearer {api_key}`
- **Extra headers**: `HTTP-Referer: {site_url}`, `X-Title: {site_name}`
- **Payload**:
  ```json
  {
    "model": "openai/gpt-4o-mini",
    "messages": [
      {"role": "system", "content": "..."},
      {"role": "user", "content": "..."}
    ],
    "max_tokens": 900,
    "temperature": 0.3,
    "response_format": {
      "type": "json_schema",
      "json_schema": {
        "name": "supervision",
        "strict": true,
        "schema": {...}
      }
    }
  }
  ```
- **Response parsing**: `choices[0].message.content` → `json_decode` → `AiSupervisionResult`
- **Latency**: mesurée via `microtime(true) * 1000`
- **Cost**: estimé = `(prompt_tokens / 1e6) * 0.15 + (completion_tokens / 1e6) * 0.60` (prix par défaut, les prix réels dépendent du modèle OpenRouter)
- **Retry**: jusqu'à 3 retries sur 429, backoff exponentiel
- **Taxonomy**: chargement dynamique depuis config `ai.supervision.taxonomy`

## Config (`config/ai.php`)

```php
'openrouter' => [
    'enabled' => env('OPENROUTER_ENABLED', false),
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
    'max_output_tokens' => env('OPENROUTER_MAX_OUTPUT_TOKENS', 900),
    'timeout' => env('OPENROUTER_TIMEOUT', 30),
    'site_name' => env('APP_NAME', 'BouclePro'),
    'site_url' => env('APP_URL', 'http://localhost'),
],
```

## Env vars (`.env.example`)

```
# OpenRouter (https://openrouter.ai)
OPENROUTER_ENABLED=false
OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=openai/gpt-4o-mini
```

## AppServiceProvider

```php
$this->app->singleton('openrouter', function ($app) {
    $config = $app['config']['ai']['openrouter'];
    return new OpenRouterSupervisionProvider(
        apiKey: $config['api_key'] ?? '',
        baseUrl: $config['base_url'],
        model: $config['model'],
        maxOutputTokens: (int) $config['max_output_tokens'],
        timeout: (int) $config['timeout'],
        siteName: $config['site_name'] ?? '',
        siteUrl: $config['site_url'] ?? '',
    );
});
```

## Controller

Ajouter dans `AVAILABLE_MODELS`:
```php
'openrouter' => 'OpenRouter (multi-modèles)',
```

---

# Tests

## OpenRouterSupervisionProviderTest (6 tests)

1. **`test_provider_implements_supervision_provider_interface`** — vérifie l'interface
2. **`test_supervise_sends_correct_payload_to_openrouter`** — HTTP fake vérifie endpoint, headers, body
3. **`test_supervise_parses_valid_json_response`** — mock response → AiSupervisionResult correct
4. **`test_supervise_throws_on_failed_response`** — HTTP 500 → SupervisionException
5. **`test_supervise_retries_on_rate_limit`** — HTTP 429 → retry → success
6. **`test_supervise_respects_model_override`** — supervise(content, model: 'anthropic/claude-3-haiku') → payload contient le bon model

---

# Constraints

- ✅ Provider standalone, pas le provider par défaut (pas de changement de binding `SupervisionProvider::class`)
- ✅ Admin lab seulement (controller AVAILABLE_MODELS)
- ✅ HTTP fake tests, pas d'appel réseau réel
- ✅ Pas de DB, pas de migration
- ✅ Pas de main, pas de PROD
- ✅ Metrics-only logging (déjà géré par STRIP_KEYS)

---

# Progress Log

## 2026-06-11 00:26:37 Europe/Paris

Task created.

## 2026-06-11 00:30:00 Europe/Paris

Plan écrit par ORCH. Prêt pour CODEUR.

---

# Tests

- [ ] OpenRouterSupervisionProviderTest (6 tests HTTP fake)
- [ ] Non-régression: AdminAiSupervisionTest, AiScenarioFactoryTest, AiBenchmarkLoggerTest

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
