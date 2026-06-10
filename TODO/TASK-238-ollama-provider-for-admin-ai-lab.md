---
task_id: TASK-238
title: Ollama provider for admin AI lab

status: MERGED

owner: OPENCODE

contributors:
  - CODEUR
  - VERIFICATOR

branch: TASK-238-ollama-provider-for-admin-ai-lab

priority: MEDIUM

created_at: 2026-06-11 00:20:59 Europe/Paris
updated_at: 2026-06-11 00:38:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-11 00:38:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter Ollama comme provider expérimental local pour `/admin/ai-supervision`. Provider standalone implémentant `SupervisionProvider`, désactivable, metrics-only.

---

# Planned Actions

- [ ] Ajouter config `ollama` dans `config/ai.php`
- [ ] Créer `app/Services/Ai/Providers/OllamaSupervisionProvider.php`
- [ ] Modifier `app/Providers/AppServiceProvider.php` (binding optionnel, pas par défaut)
- [ ] Ajouter env vars dans `.env.example`
- [ ] Créer `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php` (HTTP fake)
- [ ] Vérifier non-régression (OpenAI + LoggingSupervisionProvider toujours verts)

---

# Fichiers

| Fichier | Action |
|---|---|
| `config/ai.php` | MODIFIER (ajout section `ollama`) |
| `app/Services/Ai/Providers/OllamaSupervisionProvider.php` | CREER |
| `app/Providers/AppServiceProvider.php` | MODIFIER (ajout `OllamaSupervisionProvider` singleton, pas le défaut) |
| `.env.example` | MODIFIER (ajout vars Ollama) |
| `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php` | CREER |

---

# Architecture

## OllamaSupervisionProvider

Implémente `SupervisionProvider`.

**Constructeur** : `(string $baseUrl, string $model, int $timeout)`

**Méthode `supervise(string $content, ?string $model = null): AiSupervisionResult`** :

1. Vérifier `$this->baseUrl` non vide — sinon `SupervisionException('Ollama non configuré.')`.
2. Construire le prompt combiné (system prompt + contenu utilisateur) en un seul texte.
3. Appeler `POST {base_url}/api/generate` avec payload :
```json
{
  "model": "llama3.2",
  "prompt": "<system prompt> + <user content>",
  "stream": false,
  "format": "json",
  "options": {"num_predict": 900}
}
```
4. Parser la réponse JSON Ollama (`response` field contient le JSON output).
5. Extraire `eval_count` comme output_tokens (pas d'input tokens chez Ollama).
6. Construire `AiSupervisionResult`.
7. Gérer les erreurs : `ConnectionException` → `SupervisionException`.

**Prompt system** : réutiliser `BASE_SYSTEM_PROMPT` et `buildSystemPrompt()` de `OpenAiSupervisionProvider`. Extraire en trait partagé ? Non — dupliquer pour l'instant (TASK-242 nettoiera).

**JSON Schema** : Ollama ne supporte pas `json_schema` natif. Injecter le schéma dans le prompt system (description textuelle des champs attendus).

**Token counting** : Utiliser `eval_count` de la réponse comme `outputTokens`. Pas de `prompt_eval_count` fiable dans toutes les versions d'Ollama → `inputTokens = 0`.

**Cost** : `estimatedCostUsd = 0.0` (Ollama est local, pas de coût API).

**Latence** : mesurée entre début et fin de l'appel HTTP.

**Model override** : `supervise($content, $model)` accepte un modèle optionnel.

## Config — `config/ai.php`

```php
'ollama' => [
    'enabled' => (bool) env('OLLAMA_ENABLED', false),
    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'model' => env('OLLAMA_MODEL', 'llama3.2'),
    'timeout' => (int) env('OLLAMA_TIMEOUT', 30),
],
```

## AppServiceProvider — binding

Ajouter (ne pas remplacer le binding existant) :

```php
$this->app->singleton(OllamaSupervisionProvider::class, function ($app) {
    $config = $app['config']->get('ai.ollama');
    return new OllamaSupervisionProvider(
        baseUrl: (string) ($config['base_url'] ?? ''),
        model: (string) ($config['model'] ?? 'llama3.2'),
        timeout: (int) ($config['timeout'] ?? 30),
    );
});
```

Le controller ne l'utilise pas encore (TASK-240 fournira le selector). Pour l'instant, le provider est seulement enregistré, pas le défaut.

**Règle** : Si `OLLAMA_ENABLED=false` ou `OLLAMA_BASE_URL` vide, le provider existe mais throw une erreur propre si on tente de l'utiliser.

---

# Constraints

- ✅ Admin IA uniquement
- ✅ Provider désactivé par défaut si config absente
- ✅ Pas PROD, pas main
- ✅ Pas DB, pas migration
- ✅ Pas feature publique
- ✅ Pas ChatLoop
- ✅ Tests HTTP fake uniquement (pas de vrai Ollama lancé)
- ✅ Benchmark JSONL via LoggingSupervisionProvider (metrics-only, pas de contenu brut)
- ✅ Ne pas casser OpenAI existant
- ✅ Ne pas toucher `LoggingSupervisionProvider`

---

# Privacy Rule

**No raw prompt/content/output stored in benchmark JSONL.**
Le `AiBenchmarkLogger::STRIP_KEYS` filtre automatiquement. Le provider n'a pas à s'en préoccuper.

---

# Tests — OllamaSupervisionProviderTest

1. **`test_ollama_provider_throws_when_base_url_empty`** — constructeur sans base_url → `SupervisionException`.
2. **`test_ollama_provider_supervise_with_fake_http`** — HTTP fake, réponse Ollama valide, vérifier `AiSupervisionResult` retourné.
3. **`test_ollama_provider_handles_connection_error`** — HTTP fake avec connexion refusée → `SupervisionException`.
4. **`test_ollama_provider_handles_invalid_json_response`** — réponse Ollama avec JSON invalide → `SupervisionException`.
5. **`test_ollama_provider_uses_custom_model`** — override modèle via `supervise($content, 'mistral')`.
6. **`test_ollama_payload_contains_stream_false_and_format_json`** — vérifier que `stream: false` et `format: "json"` sont dans le payload.

---

# Progress Log

## 2026-06-11 00:20:59 Europe/Paris

Task created.

## 2026-06-11 00:25 Europe/Paris

Plan écrit par ORCH. Prêt pour CODEUR.

## 2026-06-11 00:30 Europe/Paris

CODEUR DONE. ORCH vérifié : 5 fichiers (config, provider, AppServiceProvider, .env.example, test), 44 tests, 178 assertions — tous verts. Pas de régression. Envoyé à VERIFICATOR.

## 2026-06-11 00:28 Europe/Paris

Implémentation complétée par CODEUR :
- Créé `app/Services/Ai/Providers/OllamaSupervisionProvider.php`
- Créé `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php` (6 tests)
- Modifié `config/ai.php` : ajout section `ollama`
- Modifié `app/Providers/AppServiceProvider.php` : ajout binding singleton
- Modifié `.env.example` : ajout vars Ollama

---

# Handoffs

# Tests

- [x] Non-régression OpenAI + LoggingSupervisionProvider
- [x] OllamaSupervisionProviderTest (HTTP fake)

---

# Test Results

44 passed (178 assertions). 0 failures. 0 regressions.

| Suite | Tests | Status |
|---|---|---|
| OllamaSupervisionProviderTest | 6 | ✅ verts |
| AiBenchmarkLoggerTest | 7 | ✅ verts |
| AiScenarioFactoryTest | 10 | ✅ verts |
| AdminAiSupervisionTest | 21 | ✅ verts |

---

# Review Notes

## 2026-06-11 00:35 Europe/Paris — VERIFICATOR review

Verdict : **OK** — 14 points vérifiés, tous PASS.
- Scope respecté : 2 fichiers créés + 3 modifiés, aucun non-listé
- Architecture : SupervisionProvider implémenté, binding séparé (pas le défaut)
- Sécurité : 4 cas d'erreur gérés (base_url vide, connection, HTTP non-200, JSON invalide)
- Config : section `ollama` propre, vars .env.example, désactivé par défaut
- Tests : 23 tests, 85 assertions — tous verts (6 Ollama + 7 Logger + 10 ScenarioFactory)
- Pas de régression, pas de contenu brut, pas de DB/migration
- Réserves : aucune

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Version bump is automatic at merge time via `merge-task.sh`
