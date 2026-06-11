# TASK-249 — Admin AI interactions history UI

## Metadata

- Task: `TASK-249`
- Branch: `TASK-249-admin-ai-interactions-history-ui`
- TASK file: `TODO/TASK-249-admin-ai-interactions-history-ui.md`
- Owner: `OPENCODE` / ORCH
- Implementer: `CODEUR`
- Verifier: `VERIFICATOR`
- Created: `2026-06-11 16:52:23 Europe/Paris`

---

## ORCH Launch Context

Cyril/Cockpit confirmed TASK-249 as next priority: a read-only admin UI to
browse persisted AI interactions from the `admin_ai_interactions` table
(created in TASK-247, merged into develop).

Scope: list page + detail page + sidebar nav + filters + tests.

Strictly excluded: any modification to `/admin/ai-supervision`, providers,
config, DB schema, models, destructive DB commands.

Ollama GPU WSL context noted: RTX 4070, `ministral-3:3b` for clarification,
TASK-251 separate for config/runtime alignment.

---

## Mandatory Reading Before Implementation

CODEUR must read before coding:

- `AGENTS.md`
- `.agents/skills/tmux-smt-conversation-protocol/SKILL.md`
- `ai-local/README.md`
- `ai/tooling/mcp-tools.md`
- `ai/tooling/terminal-tools.md`
- `TODO/TASK-249-admin-ai-interactions-history-ui.md` (TASK file)
- this conversation file (active thread)

---

## Code Architecture Notes

### Existing Patterns

- **Admin list pattern**: inline HTML tables, `<x-admin-layout title="...">`, no shared table component
- **Row action pattern**: `<a href="{{ route('admin.X.edit', $item) }}">Modifier</a>` for detail
- **Filter pattern**: GET form with `<select>` and search `<input>`, reset link when active
- **Sidebar pattern**: PHP array `$iaItems[]` in `admin.blade.php`, IA group collapsible

### Model Available

```php
AdminAiInteraction {
    id (UUID), organization_id, user_id, scenario_id, provider, model, status,
    input_excerpt, input_hash, input_length, result_summary, result_payload (JSON),
    metadata (JSON), input_tokens, output_tokens, latency_ms, cost_usd,
    created_at, updated_at
}
```

### Routes Convention

```
GET /admin/ai-interactions          → index()    → admin.ai-interactions
GET /admin/ai-interactions/{id}     → show()     → admin.ai-interactions.show
```

### Sidebar Nav

Add to `$iaItems[]` array in `admin.blade.php`:
```php
$iaItems[] = ['route' => 'admin.ai-interactions', 'label' => 'Historique IA', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z']; // grid icon
```

Place after Supervision IA line (line 259 in current `admin.blade.php`).

---

## Design Constraints

- Table style: follow existing admin pattern (rounded-xl border, divide-y rows)
- Detail page: `result_payload` as formatted JSON in `<pre>` with syntax highlighting or indented
- Filters preserve via GET params in pagination links: use `->appends(request()->query())`
- No Alpine/Livewire needed — pure Blade + vanilla JS
- Empty state: "Aucune interaction IA enregistrée" centered
- Route model binding: `AdminAiInteraction` model with implicit binding

---

## DB Safety Rules

**Critical**: CODEUR must run this preflight before any Laravel test command:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database
```

Expected output:
```text
database.connections.pgsql.database = bouclepro_test
```

If it resolves to `bouclepro`, STOP. Fix test configuration first.

Only allowed test database: `bouclepro_test`.

Run only targeted sequential tests:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Admin/AdminAiInteractionTest.php --parallel --no   # NO — sequential only
```

Use sequential:
```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Admin/AdminAiInteractionTest.php
```

For regression:
```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Admin/AdminAiSupervisionTest.php
```

---

## Required Test Assertions

- Unauthenticated user redirected to login
- Non-admin user gets 403
- Admin can view list page (200)
- List shows interactions ordered by `created_at DESC`
- Pagination works (check second page if > 25 interactions)
- Filters work: provider, scenario, status, search, date range
- Detail page shows all fields
- Detail page renders `result_payload` as formatted JSON
- Empty state message when no interactions exist

---

## CODEUR Completion Report Required

CODEUR must update this file and the TASK file with:

- implementation summary
- exact modified files
- DB preflight output
- exact tests run and results
- explicit confirmation that no supervision/config/provider/model/schema files were modified
- explicit confirmation that no destructive DB command was run
- commit hash and push status

CODEUR must also send a short SMT to ORCH after updating this conversation:

```text
[YYYY-MM-DD HH:MM][TASK-249][CODEUR→ORCH][DONE] ...
```

---

## VERIFICATOR Checklist

VERIFICATOR read-only checks after CODEUR DONE:

- [ ] List page accessible at `/admin/ai-interactions`
- [ ] Table columns correct (date, scenario, provider, model, status, excerpt, latency, cost)
- [ ] Pagination (25/page) avec `->appends(request()->query())`
- [ ] Detail page shows full record with formatted JSON
- [ ] Filters work (provider, scenario, status, search, date range)
- [ ] Empty state when no data
- [ ] Sidebar link `Historique IA` in IA group, active on these routes
- [ ] No changes to `/admin/ai-supervision`, providers, config, schema, models
- [ ] `AdminAiSupervisionTest` regression still green
- [ ] DB-safe preflight `bouclepro_test`
- [ ] No destructive DB command run
- [ ] TASK and conversation updated

VERIFICATOR must update this file and send short SMT to ORCH:

```text
[YYYY-MM-DD HH:MM][TASK-249][VERIFICATOR→ORCH][OK] ...
```

or `BLOCKED` / `OK_WITH_RESERVES` with clear details.

---

## SMT Log

### 2026-06-11 16:52 Europe/Paris — ORCH to CODEUR

```text
[2026-06-11 16:52][TASK-249][branch:TASK-249-admin-ai-interactions-history-ui][ORCH→CODEUR][ACTION] Lire AGENTS.md, tmux SMT skill, ai-local/README.md, ai/tooling docs, TASK file et ai-local/conversations/20260611-16h52-TASK-249-ai-interactions-history-ui.md. Implémenter UI historique interactions IA admin (liste + détail + sidebar + filtres). Scope strict: pas de supervision/pas de providers/pas de config/pas de schema. DB preflight bouclepro_test. Tests ciblés séquentiels. Répondre DONE dans la conversation + SMT court.
```

### 2026-06-11 17:15 Europe/Paris — CODEUR DONE

```
[2026-06-11 17:15][TASK-249][CODEUR→ORCH][DONE] Controller/views/routes/sidebar/tests créés. 13 tests pass (38 assertions). Regression AdminAiSupervisionTest 48 pass (187 assertions). DB preflight bouclepro_test OK. Scope clean (pas de supervision/config/provider/schema/model). Commit 1008f11 + fbf034c pushed.
```

### 2026-06-11 ~17:17 Europe/Paris — ORCH to VERIFICATOR

```text
[2026-06-11 17:17][TASK-249][ORCH→VERIFICATOR][ACTION] VERIFICATOR checklist dans conversation + TASK file. Lire tout. Vérifier scope strict + tests + sidebar + UI. Répondre OK/BLOCKED/OK_WITH_RESERVES dans conversation + SMT court.
```
