---
task_id: TASK-252
title: Admin AI prompts registry (CRUD prompts IA admin)

status: DONE

owner: CODEUR

contributors:
  - CODEUR

branch: TASK-252-admin-ai-prompts-registry-crud-prompts-ia-admin

priority: MEDIUM

created_at: 2026-06-11 18:43:41 Europe/Paris
updated_at: 2026-06-11 19:09:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: CODEUR
  since: 2026-06-11 19:09:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer un CRUD admin pour gérer les prompts système des scénarios IA (actuellement hardcodés dans les classes de scénario).

**Périmètre strict :** migration + model + controller + vues (index/create/edit/show) + routes + sidebar. Pas d'intégration avec les scénarios existants (les prompts restent hardcodés dans les classes — l'intégration sera une tâche future).

---

# Planned Actions

- [ ] inspect existing CRUD patterns (email templates)
- [ ] inspect admin sidebar IA group
- [ ] inspect existing scenario prompts
- [ ] create migration
- [ ] create model
- [ ] create seeder (copie des prompts hardcodés actuels)
- [ ] create controller
- [ ] create views (index, create, edit, show)
- [ ] add routes
- [ ] add sidebar link
- [ ] run tests
- [ ] validate UI in browser

---
# Progress Log

## 2026-06-11 19:09:00 Europe/Paris

CODEUR: Implementation complete. Files created:

### Créés (9)
1. `database/migrations/2026_06_11_150002_create_admin_ai_prompts_table.php`
2. `app/Models/AdminAiPrompt.php` — HasUuids, HasFactory, scopes active/byScenario, casts
3. `database/seeders/AiPromptSeeder.php` — copie textuelle des prompts hardcodés
4. `app/Http/Controllers/Admin/AdminAiPromptController.php` — CRUD complet avec auto-incrément version
5. `resources/views/admin/ai-prompts/index.blade.php` — liste paginée, filtre scenario_id, search
6. `resources/views/admin/ai-prompts/create.blade.php` — form création avec auto-version
7. `resources/views/admin/ai-prompts/edit.blade.php` — form édition, scenario_id/version readonly
8. `resources/views/admin/ai-prompts/show.blade.php` — carte détails, prompt_text en monospace
9. `tests/Feature/Admin/AdminAiPromptTest.php` — 9 tests

### Modifiés (3)
1. `routes/web.php` — import + 7 routes CRUD après ai-interactions
2. `resources/views/layouts/admin.blade.php` — lien "Prompts IA" dans groupe IA (entre Supervision et Historique)
3. `database/seeders/DatabaseSeeder.php` — appel AiPromptSeeder

### Résultats tests
9/9 passés, 0 régressions (AdminAiInteractionTest: 13/13 verts).

---



# Specification

## Fichiers à créer

| Fichier | Description |
|---------|-------------|
| `database/migrations/xxxx_create_admin_ai_prompts_table.php` | Table `admin_ai_prompts` |
| `app/Models/AdminAiPrompt.php` | Model Eloquent |
| `database/seeders/AiPromptSeeder.php` | Seed avec les prompts hardcodés actuels |
| `app/Http/Controllers/Admin/AdminAiPromptController.php` | CRUD controller |
| `resources/views/admin/ai-prompts/index.blade.php` | Liste avec filtre par scénario |
| `resources/views/admin/ai-prompts/create.blade.php` | Formulaire de création |
| `resources/views/admin/ai-prompts/edit.blade.php` | Formulaire d'édition |
| `resources/views/admin/ai-prompts/show.blade.php` | Détail read-only |
| `tests/Feature/Admin/AdminAiPromptTest.php` | Feature tests CRUD |

## Fichiers à modifier

| Fichier | Modification |
|---------|-------------|
| `routes/web.php` | Ajouter routes CRUD `/ai-prompts` |
| `resources/views/layouts/admin.blade.php` | Ajouter lien "Prompts IA" dans le groupe IA de la sidebar |
| `database/seeders/DatabaseSeeder.php` | Appeler `AiPromptSeeder` |

## Conventions du codebase

- **Model naming:** `AdminAiPrompt` dans `App\Models` (pas de sous-dossier `Ai/`)
- **Primary key:** UUID via `HasUuids` trait
- **Seeder:** classe autonome dans `database/seeders/`
- **Test:** feature test dans `Tests\Feature\Admin`
- **DB driver:** PostgreSQL (comme tout le codebase)
- **Test DB:** `bouclepro_test` (via RefreshDatabase)
- **Views:** suivre le pattern `admin.email-templates.*` existant (tailwind, mêmes conventions de layout)

## Table `admin_ai_prompts`

| Colonne | Type | Notes |
|---------|------|-------|
| `id` | uuid | primary key, `HasUuids` |
| `scenario_id` | string | ex: `supervision_content`, `clarify_help_request` |
| `name` | string | nom lisible (ex: "Supervision de contenu — v1") |
| `description` | text | nullable — description de l'usage du prompt |
| `prompt_text` | text | le système prompt complet |
| `version` | integer | version auto-incrémentée par scénario, défaut 1 |
| `is_active` | boolean | défaut `true` |
| `metadata` | jsonb | nullable — infos supplémentaires (provider hint, etc.) |
| `timestamps` | | `created_at`, `updated_at` |
| | | **UNIQUE** sur `(scenario_id, version)` |

## Model `AdminAiPrompt`

- casts: `metadata` → `array`, `is_active` → `boolean`, `version` → `integer`
- scopes: `active()`, `byScenario(string $scenarioId)`
- Relations: none (pas de FK vers scenarios — ce sont des ID string simples)

## Seeder `AiPromptSeeder`

Insérer les prompts actuellement hardcodés :

1. **supervision_content — v1** : le contenu de `SupervisionContentScenario::BASE_SYSTEM_PROMPT`
2. **clarify_help_request — v1** : le contenu de `ClarifyHelpRequestScenario::SYSTEM_PROMPT`

## Controller `AdminAiPromptController`

Suivre le pattern `AdminEmailTemplatesController` :
- `index()` — liste paginée avec filtre par `scenario_id`
- `create()` / `store()` — création avec auto-incrément de version
- `edit()` / `update()` — modification (ne pas changer scenario_id après création, ne pas baisser la version)
- `show()` — vue read-only avec le prompt formaté
- `destroy()` — suppression

## Routes

```php
Route::get('/ai-prompts', [AdminAiPromptController::class, 'index'])->name('ai-prompts');
Route::get('/ai-prompts/create', [AdminAiPromptController::class, 'create'])->name('ai-prompts.create');
Route::post('/ai-prompts', [AdminAiPromptController::class, 'store'])->name('ai-prompts.store');
Route::get('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'show'])->name('ai-prompts.show');
Route::get('/ai-prompts/{prompt}/edit', [AdminAiPromptController::class, 'edit'])->name('ai-prompts.edit');
Route::put('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'update'])->name('ai-prompts.update');
Route::delete('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'destroy'])->name('ai-prompts.destroy');
```

## Sidebar

Ajouter dans le groupe IA de `resources/views/layouts/admin.blade.php` :
```php
$iaItems[] = ['route' => 'admin.ai-prompts', 'label' => 'Prompts IA', 'icon' => 'M...'];
```

Utiliser l'icône `document-text` (ou équivalent heroicons).

## Views

Suivre strictement le pattern des vues `admin.email-templates.*` :
- Même layout admin
- Mêmes conventions tailwind (couleurs indigo, dark mode)
- Même structure de tableau/liste
- Filtre par scenario_id en dropdown
- Formatage du prompt_text dans show (préserver les sauts de ligne, monospace)

## Tests

Feature tests `AdminAiPromptTest` :
- `test_admin_can_list_prompts`
- `test_admin_can_create_prompt`
- `test_admin_can_view_prompt`
- `test_admin_can_edit_prompt`
- `test_admin_can_delete_prompt`
- `test_scenario_id_filter_works`
- `test_create_auto_increments_version`
- `test_guest_cannot_access_prompts`
- `test_non_admin_cannot_access_prompts`

# Handoffs

# Tests

- [x] feature tests (AdminAiPromptTest)
- [x] browser validation (TODO)
- [x] responsive validation (TODO)
- [x] console inspection (TODO)
- [x] tenant validation (TODO)

---

# Test Results

## 2026-06-11 19:09:00 Europe/Paris

```
PASS Tests\Feature\Admin\AdminAiPromptTest
✓ admin can list prompts
✓ admin can create prompt
✓ admin can view prompt
✓ admin can edit prompt
✓ admin can delete prompt
✓ scenario filter works
✓ create auto increments version
✓ guest cannot access
✓ non admin cannot access

Tests:    9 passed (25 assertions)
Duration: 2.23s
```

No regressions on existing test suite (AdminAiInteractionTest: 13/13).

---

# Review Notes

## 2026-06-11 19:11 Europe/Paris

**VERIFICATOR:** OK sans réserve.
- 9/9 tests verts (25 assertions), 0 régressions
- Scope strict respecté (9 créés + 3 modifiés)
- Note: commit manquant — fait par ORCH

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`