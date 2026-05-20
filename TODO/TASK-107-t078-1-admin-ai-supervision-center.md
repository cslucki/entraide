---
task_id: TASK-107
title: T078.1 — Admin AI Supervision Center

status: IN_PROGRESS

owner: CLAUDE

contributors: []

branch: TASK-107-t078-1-admin-ai-supervision-center

priority: MEDIUM

created_at: 2026-05-20 17:27:10 Europe/Paris
updated_at: 2026-05-20 17:27:10 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: CLAUDE
  since: 2026-05-20 17:27:10 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Mettre en place un Centre de supervision IA pour les administrateurs : interface admin
permettant de soumettre un extrait de contenu (message, demande, post) et d'obtenir
une analyse structurée via OpenAI gpt-4o-mini (Responses API, JSON Schema strict),
sans stocker les requêtes côté OpenAI.

---

# Planned Actions

- [x] inspect architecture (admin layout, AI services, routes)
- [x] créer config/ai.php (clés OpenAI + supervision flag)
- [x] mettre à jour .env.example uniquement (jamais .env réel)
- [x] créer DTO AiSupervisionResult + exception dédiée
- [x] créer SupervisionProvider contract + OpenAiSupervisionProvider (Responses API)
- [x] binder dans AppServiceProvider
- [x] créer AdminAiSupervisionController + routes admin
- [x] ajouter entrée nav "Supervision IA" dans layouts/admin.blade.php
- [x] créer vue resources/views/admin/ai-supervision/index.blade.php
- [x] tests feature avec Http::fake + Http::preventStrayRequests
- [x] vérifier qu'aucune fuite de clé/Bearer dans la sortie HTML
- [x] vendor/bin/pint --dirty --format agent

---
# Progress Log


## 2026-05-20 17:27:10 Europe/Paris

Task created.

Owner:
CLAUDE

Branch:
TASK-107-t078-1-admin-ai-supervision-center

Status:
IN_PROGRESS

---

## 2026-05-20 — Implémentation T078.1

Architecture mise en place :

- `config/ai.php` (créé — n'existait pas) : section `openai` (api_key, base_url, model,
  max_output_tokens, timeout, input_price_per_1m, output_price_per_1m) + section
  `supervision.enabled`.
- `.env.example` étendu avec `OPENAI_*` et `AI_SUPERVISION_ENABLED` (le vrai `.env`
  n'a pas été touché).
- DTO `App\Services\Ai\DTO\AiSupervisionResult` (immutable readonly) : summary,
  risk_level, categories, recommendations, moderation_flag, notes + télémétrie
  (tokens, modèle, coût USD estimé, latence ms).
- Contract `App\Services\Ai\Contracts\SupervisionProvider`.
- Exception dédiée `App\Services\Ai\Exceptions\SupervisionException`.
- Provider `App\Services\Ai\Providers\OpenAiSupervisionProvider` :
  - endpoint POST `{base_url}/responses` (Responses API)
  - payload : `max_output_tokens` (PAS `max_tokens`), `store: false`,
    `text.format.type = json_schema` + `strict: true` + schéma JSON exhaustif
    (`additionalProperties: false`, tous les champs `required`)
  - parsing tolérant de `output_text` (clé directe ou via `output[].content[]`)
  - calcul coût USD : `(in/1M)*input_price + (out/1M)*output_price`
  - aucun tool call, aucun stream, aucun RAG
- Binding dans `AppServiceProvider::register()` (lit `config('ai.openai')`).
- Controller `AdminAiSupervisionController` : `index()` + `analyze()` ; capte les
  `SupervisionException` et les remonte dans la vue plutôt que de crasher.
- Routes `admin.ai-supervision` (GET) et `admin.ai-supervision.analyze` (POST),
  protégées par `auth` + `admin`.
- Entrée nav "Supervision IA" ajoutée dans `layouts/admin.blade.php` (visible en
  prod, contrairement au Lab IA qui reste dev-only).
- Vue `admin/ai-supervision/index.blade.php` : formulaire + carte résultat avec
  badge risque (low/medium/high), drapeau modération, catégories, recommandations,
  notes, et bloc télémétrie (modèle, tokens, coût, latence).

Sécurité :

- La clé API n'est jamais affichée dans la vue ni dans les messages d'erreur
  utilisateur (le test `api_key_and_bearer_never_leak_in_response` le verrouille).
- En cas de désactivation (`AI_SUPERVISION_ENABLED=false`), `analyze()` retourne 403.

---

# Handoffs

# Tests

- [x] feature tests (10/10 passants)
- [x] browser validation (pendant)
- [x] responsive validation (pendant)
- [x] console inspection (pendant)
- [x] tenant validation (route admin globale, hors scope tenant)

---

# Test Results

`php artisan test --filter=AdminAiSupervisionTest`

```
Tests\Feature\Admin\AdminAiSupervisionTest
  ✓ guest cannot access supervision center
  ✓ non admin cannot access supervision center
  ✓ admin can view supervision index
  ✓ admin can analyze content with mocked openai response
  ✓ payload uses responses api with max output tokens and json schema
  ✓ invalid content is rejected
  ✓ api key and bearer never leak in response
  ✓ authorization bearer header is sent to openai
  ✓ openai failure is caught and shown to admin
  ✓ disabled supervision blocks analyze

Tests: 10 passed (30 assertions)
```

Tous les tests utilisent `Http::fake()` + `Http::preventStrayRequests()` : aucun
appel réseau réel n'est effectué.

---

# Review Notes

Points d'attention pour la revue :

- Le provider est construit via le container avec lecture de `config('ai.openai')`,
  ce qui rend l'override en test trivial (`config([...])` dans `setUp()`).
- Le schéma JSON est strict (`strict: true`, `additionalProperties: false`,
  tous champs `required`) — c'est l'option à privilégier sur `json_object` selon
  les corrections RUN T078.0A.
- `store: false` est explicitement envoyé pour ne rien conserver côté OpenAI.
- Aucun secret n'apparaît dans la sortie HTML (test dédié).
- L'entrée nav est visible en prod : si on souhaite la cacher en prod tant que
  T078.1 n'est pas validé en staging, ajouter un `if (!app()->isProduction())`
  autour de l'ajout dans `layouts/admin.blade.php`.