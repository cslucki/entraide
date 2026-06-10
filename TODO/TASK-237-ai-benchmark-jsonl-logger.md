---
task_id: TASK-237
title: AI benchmark JSONL logger

status: DONE

owner: OPENCODE

contributors:
  - CODEUR
  - VERIFICATOR

branch: TASK-237-ai-benchmark-jsonl-logger

priority: MEDIUM

created_at: 2026-06-10 22:00:08 Europe/Paris
updated_at: 2026-06-10 22:58:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-10 22:58:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter un logger JSONL pour benchmarker les appels AI : latence, tokens, coût, scénario, modèle. File-based seulement, pas de DB.

---

# Planned Actions

- [ ] Créer `app/Services/Ai/Logging/AiBenchmarkLogger.php` — écriture JSONL
- [ ] Créer `app/Services/Ai/Providers/LoggingSupervisionProvider.php` — decorator
- [ ] Modifier `app/Providers/AppServiceProvider.php` — wrapper le SupervisionProvider
- [ ] Modifier `app/Http/Controllers/Admin/AdminAiSupervisionController.php` — logger pour clarify_help_request
- [ ] Créer `tests/Unit/Services/Ai/AiBenchmarkLoggerTest.php`
- [ ] Vérifier que le système existant n'est pas cassé (pas de régression)

---

# Fichiers

| Fichier | Action |
|---|---|
| `app/Services/Ai/Logging/AiBenchmarkLogger.php` | CREER |
| `app/Services/Ai/Providers/LoggingSupervisionProvider.php` | CREER |
| `app/Providers/AppServiceProvider.php` | MODIFIER (binding modifié) |
| `app/Http/Controllers/Admin/AdminAiSupervisionController.php` | MODIFIER (+2 lignes de log) |
| `tests/Unit/Services/Ai/AiBenchmarkLoggerTest.php` | CREER |

---

# Architecture

## AiBenchmarkLogger (service)

- **Injection**: `$basePath` via constructeur, défaut `storage/logs/ai-benchmarks/`
- **Méthode** `log(array $entry): void` — écrit une ligne JSON dans `{scenario_id}.jsonl`
- **Format d'entrée**:

```json
{
  "timestamp": "2026-06-10T22:00:00+02:00",
  "scenario_id": "supervision_content",
  "model": "gpt-4o-mini",
  "input_content": "Le message utilisateur...",
  "output": {"risk_assessment": {...}},
  "input_tokens": 42,
  "output_tokens": 150,
  "latency_ms": 1234.56,
  "cost_usd": 0.000123,
  "meta": {"attempt": 1}
}
```

- **Propriétés**:
  - Crée le dossier `storage/logs/ai-benchmarks/` s'il n'existe pas
  - Append-only (basename filtré pour éviter path traversal)
  - **Ne jamais throw** — les erreurs sont silencieuses (ne pas casser l'appel AI)

## LoggingSupervisionProvider (decorator)

- Implémente `SupervisionProvider`
- Constructeur: `SupervisionProvider $inner, AiBenchmarkLogger $logger`
- `supervise(string $content, ?string $model = null): AiSupervisionResult`
  - Appelle `$inner->supervise()`
  - Extrait les métriques du `AiSupervisionResult` retourné
  - Appelle `$logger->log()` avec les données
  - Retourne le résultat inchangé

## AppServiceProvider — binding

Remplacer :
```php
$this->app->bind(SupervisionProvider::class, OpenAiSupervisionProvider::class);
```
Par :
```php
$this->app->singleton(SupervisionProvider::class, function ($app) {
    return new LoggingSupervisionProvider(
        new OpenAiSupervisionProvider(),
        $app->make(AiBenchmarkLogger::class)
    );
});
```

## Controller — clarify_help_request

Dans le bloc `if ($selectedScenario === 'clarify_help_request')`, ajouter après extraction de `$result` :
```php
app(AiBenchmarkLogger::class)->log([...]);
```

---

# Tests

## AiBenchmarkLoggerTest

1. **`test_logger_creates_directory_and_writes_jsonl`**
   - Créer un dossier temporaire, instancier AiBenchmarkLogger
   - Appeler log() avec des données
   - Vérifier que le fichier `scenario.jsonl` existe et contient une ligne JSON valide

2. **`test_logger_appends_to_existing_file`**
   - Deux appels log() successifs
   - Vérifier que le fichier contient 2 lignes

3. **`test_logger_does_not_throw_on_write_error`**
   - Base path non-writable
   - log() ne throw pas

4. **`test_logger_filters_dangerous_characters_in_filename`**
   - scenario_id = `../../malicious`
   - Le fichier doit être `.._.._malicious.jsonl` dans le base path

---

# Constraints

- ✅ File-based only, pas de DB
- ✅ Silent failure: ne jamais casser l'appel AI
- ✅ Ne pas toucher `OpenAiSupervisionProvider` (ajouté en wrapper, pas en modification interne)
- ✅ Ne pas toucher l'interface `SupervisionProvider`
- ✅ Tests unitaires seulement (pas de feature test — le JSONL logger est un détails d'infrastructure)
- ✅ `storage/logs/` est dans `.gitignore`

---

# Progress Log

## 2026-06-10 22:00:08 Europe/Paris

Task created.

## 2026-06-10 22:38 Europe/Paris

Plan écrit par ORCH. Prêt pour CODEUR.

## 2026-06-10 22:58 Europe/Paris

VERIFICATOR review OK.
- 15 tests, 52 assertions — tous verts
- Scope respecté, sécurité OK, architecture OK, régression OK
- Verdict : OK — prêt pour check/finalize/merge

## 2026-06-10 22:50 Europe/Paris

- CODEUR DONE signal (5 fichiers, 5+10 tests verts)
- ORCH vérifié code (correct) — RefreshDatabase flaky mais non lié au code
- Envoyé à VERIFICATOR pour review
- Status → TESTING, lock → VERIFICATOR
