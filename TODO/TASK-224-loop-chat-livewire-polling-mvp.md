---
task_id: TASK-224
title: Loop Chat Livewire Polling MVP

status: DONE

owner: CODEUR

contributors:
  - OPENCODE
  - CODEUR

branch: TASK-224-loop-chat-livewire-polling-mvp

priority: MEDIUM

created_at: 2026-06-07 21:10:01 Europe/Paris
updated_at: 2026-06-07 22:24:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Donner au chat de Boucle une sensation de quasi temps réel pour la bêta, sans Reverb, Echo, WebSocket ni nouvelle infrastructure, en utilisant Livewire polling.

Le MVP doit rafraîchir périodiquement les messages de la Boucle, permettre l'envoi de messages texte via le service existant, préserver le composer et le texte en cours de saisie, et éviter tout refresh complet de page.

Hors scope strict : fichiers, replies, OpenGraph, présence en ligne, typing indicator, read receipts, Reverb/Echo/WebSocket, refonte complète du chat.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-07 21:10:23 Europe/Paris

Planning/audit completed by OPENCODE before CODEUR handoff.

Current chat state:

- `resources/views/loops/show.blade.php` is currently Blade classic.
- `LoopController@show()` loads all loop messages and passes `$messages` to the view.
- Text send currently posts to `LoopController@storeMessage()` and redirects back to `loops.show`.
- `LoopMessageService::sendUserMessage()` already enforces active Loop membership and same Organization.
- Existing Livewire convention is class-based: `app/Livewire/*` plus `resources/views/livewire/*`.
- Existing transaction chat uses `wire:poll.3000ms`, proving Livewire polling is already available.

Implementation direction:

- Create a lightweight class-based `LoopChat` Livewire component.
- Replace only the Loop chat messages list and text composer.
- Poll only the message area/component, never the whole page layout.
- Keep join flow and help-request forms outside this MVP unless CODEUR finds a strict integration need.
- Preserve TASK-223 organization-scoped navigation behavior.

Conversation file:

- `ai-local/conversations/20260607-21h10-TASK-224-loop-chat-livewire-polling.md`

Status:
Handed off to CODEUR for implementation.


## 2026-06-07 21:10:01 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-224-loop-chat-livewire-polling-mvp

Status:
IN_PROGRESS

## 2026-06-07 22:00:00 Europe/Paris

CODEUR implementation complete.

### Fichiers créés / modifiés

- `app/Livewire/LoopChat.php` (nouveau) — class-based Livewire component
- `resources/views/livewire/loop-chat.blade.php` (nouveau) — view avec wire:poll.3s, messages, composer, Alpine scroll
- `app/Http/Controllers/LoopController.php` — show() simplifié (suppression chargement $messages, délégué à Livewire)
- `resources/views/loops/show.blade.php` — remplacement du bloc messages + formulaire d'envoi par `@livewire('loop-chat', ...)`
- `tests/Feature/Livewire/LoopChatTest.php` (nouveau) — 10 tests

### Architecture retenue

Composant Livewire class-based `LoopChat` :
- `public Loop $loop` — reçoit la boucle depuis la vue Blade
- `public string $body = ''` — model du textarea
- `public bool $isMember = false` — déterminé dans mount()
- `sendMessage()` — valide body, appelle `LoopMessageService::sendUserMessage()`, vide body après succès
- `render()` — charge les messages de la loop avec `sender` eager-loaded

Zone de polling : wrapping `<div wire:poll.3s>` autour de la liste des messages uniquement.

Scroll : Alpine `x-data="{ atBottom: true }"` avec détection scroll, `$nextTick` après `livewire:updated` si en bas.

Composer resté hors composant :
- Bouton "Rejoindre" (non-membre, loop publique)
- Formulaire analyse demande d'aide (via session `help_request_analysis`)
- Formulaire "Qui peut m'aider ?" (toggle + analyse)
- Tous en Blade classique inchangés

### Tests

```
php artisan test tests/Feature/Livewire/LoopChatTest.php --compact
# 10/10 passed, 17 assertions
```

Tests validés :
1. component loads messages for loop ✓
2. member can send message ✓
3. non-member cannot send ✓
4. cross-organization blocked ✓
5. requires body to send ✓
6. body max length is 5000 ✓
7. non-member does not see composer ✓
8. member sees composer ✓
9. help request messages are displayed ✓
10. body not lost during render ✓

### Régression

```
php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# 13/13 passed (33 assertions)

php artisan test tests/Feature/Livewire/ --compact
# 35/35 passed (56 assertions)

php artisan test tests/Feature/LoopModelTest.php --compact
# 19/19 passed
```

Aucune régression.

### git status

```text
## TASK-224-loop-chat-livewire-polling-mvp...origin/TASK-224-loop-chat-livewire-polling-mvp
 M app/Http/Controllers/LoopController.php
 M resources/views/loops/show.blade.php
?? app/Livewire/LoopChat.php
?? resources/views/livewire/loop-chat.blade.php
?? tests/Feature/Livewire/LoopChatTest.php
```

## 2026-06-07 22:10:00 Europe/Paris

VERIFICATOR returned `OK` for the CODEUR implementation.

Verified scope:

- tenant isolation preserved via `LoopMessageService::sendUserMessage()`;
- no Reverb/Echo/WebSocket/new infrastructure;
- Livewire polling MVP accepted with minor reserves;
- no files/replies/OpenGraph functional changes.

Minor reserves documented in `ai-local/conversations/20260607-21h10-TASK-224-loop-chat-livewire-polling.md`:

- `livewire:updated.window` can react to other Livewire components on the same page;
- `LoopController::storeMessage()` remains as legacy/dead route after Livewire composer integration;
- no automated browser/Playwright validation was run.

## 2026-06-07 22:18:00 Europe/Paris

Runtime bug reported before merge:

```text
GET /org/main/loops/{loop}
LoopController::show(): Argument #1 ($loop) must be of type App\Models\Loop, string given
```

Root cause: organization-prefixed loop routes include both `{organization}` and `{loop}` parameters, while shared `LoopController` actions were typed for root routes only. Laravel passed the `{organization}` slug as the first positional controller argument.

Fix applied in this task before merge:

- added `LoopController::resolveRouteLoop()` to normalize root and organization-prefixed route arguments;
- updated loop actions that receive a Loop route parameter (`show`, `join`, `leave`, `analyzeHelpIntention`, `publishHelpRequest`, `addMember`, `storeMessage`) to support both root `/loops/{loop}` and `/org/{organization}/loops/{loop}` routes;
- added regression test `test_organization_prefixed_loop_show_resolves_loop_parameter()`.

Status returned to OPENCODE for final validation and cleanup. No merge without Cyril GO.

## 2026-06-07 22:24:00 Europe/Paris

Final OPENCODE validation complete after runtime route bug fix.

Safe DB preflight confirmed:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default
# database.default = pgsql

APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database
# database.connections.pgsql.database = bouclepro_test
```

Initial parallel execution of `LoopOrganizationModeTest` and `LoopChatTest` caused `RefreshDatabase` races on the shared PostgreSQL test database (`migrations`/duplicate table errors). This did not touch the runtime database and was resolved by rerunning the suites sequentially.

Sequential validation passed:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# 14 passed, 35 assertions

APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Livewire/LoopChatTest.php --compact
# 10 passed, 17 assertions

git diff --check
# passed
```

Status set to `DONE`, lock `UNLOCKED`. Branch must not be merged until Cyril gives explicit GO.

# Handoffs

## 2026-06-07 21:10:09 Europe/Paris

Previous Owner:
OPENCODE

New Owner:
CODEUR

Status:
IN_PROGRESS

---


# Tests

- [x] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

```
php artisan test tests/Feature/Livewire/LoopChatTest.php --compact
# 10/10 passed, 17 assertions

php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# 14/14 passed (35 assertions) — includes organization-prefixed route regression

php artisan test tests/Feature/Livewire/ --compact
# 35/35 passed (56 assertions)

php artisan test tests/Feature/LoopModelTest.php --compact
# 19/19 passed — no regression
```

All tests pass. Zero regressions.

Final OPENCODE validation after the route parameter bug fix:

```bash
APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/LoopOrganizationModeTest.php --compact
# 14 passed, 35 assertions

APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan test tests/Feature/Livewire/LoopChatTest.php --compact
# 10 passed, 17 assertions

git diff --check
# passed
```

Browser/responsive/console validation was not automated with Playwright for this task. VERIFICATOR accepted this as a minor non-blocking reserve for the MVP.

---

# Review Notes

VERIFICATOR is mandatory before merge. Review must focus on tenant safety, Livewire polling scope, input preservation, scroll behavior, and absence of Reverb/Echo/WebSocket/new infrastructure.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
