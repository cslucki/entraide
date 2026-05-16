---
task_id: TASK-074.7
title: IA-assisted Help Request in Loops

status: DONE

owner: OPENCODE

contributors: []

branch: T074.7-t074-7-ia-assisted-help-request-in-loops

priority: MEDIUM

created_at: 2026-05-15 21:47:26 Europe/Paris
updated_at: 2026-05-16 07:20:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: READY
  url: null
---

# Objective

T074.7 — IA-assisted Help Request in Loops.

Permettre à un Member de taper une intention floue, puis BouclePro l'aide à créer une demande d'aide claire, validée humainement, envoyée dans la Loop active.

Phrase cœur :
"Je tape une intention floue, et BouclePro m'aide à créer une vraie demande d'aide envoyée au bon cercle."

Parcours attendu :
1. Member ouvre une Loop.
2. Il saisit une intention floue dans une interface calme "Qui peut m'aider ?".
3. BouclePro génère une proposition de demande clarifiée via FakeAIProvider.
4. Le Member voit la demande clarifiée (preview éditable).
5. Le Member peut modifier/valider.
6. Rien n'est publié sans validation humaine explicite.
7. Après validation, une carte/message structuré "Demande d'aide" apparaît dans la Loop.
8. Le parcours reste mobile-first.

---

# Constraints

- `POST /loops/{loop}/help-request/analyze` — analyse l'intention via FakeAIProvider
- `POST /loops/{loop}/help-request/publish` — publie la demande validée
- LoopMessage type='help_request' avec metadata structurée
- FakeAIProvider uniquement (pas OpenAI réel)
- Pas de Reverb
- Pas de notifications email
- Pas de Calm Notifications Layer
- Pas de migration Community → Organization
- Community/community_id reste legacy technique temporaire
- Organization = Tenant
- Loop ≠ Tenant
- Session flash = preview uniquement, jamais confiance pour publication
- Revalidation serveur de tous les champs avant publication

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] extend LoopMessageService (sendHelpRequestMessage)
- [x] add routes + controller methods (analyzeHelpIntention, publishHelpRequest)
- [x] update show.blade.php (help request flow + help request card)
- [x] feature tests (LoopHelpRequestTest)
- [x] Playwright spec (member-help-request.spec.js)
- [x] capture screenshots
- [x] run tests, fix issues
- [x] task finalization

---

# Progress Log

## 2026-05-15 21:47:26 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
T074.7-t074-7-ia-assisted-help-request-in-loops

Status:
IN_PROGRESS

## 2026-05-15 21:50:00 Europe/Paris

Audit complete + plan validated.

Inspected:
- T074.6 merged: LoopController, loops/show.blade.php, LoopMessageService, Playwright helpers
- T074.1A done: AiProvider interface, FakeAIProvider (9 scenarios), AssistedInteractionLabResult DTO
- LoopMessage model: fillable [loop_id, sender_id, body, type, metadata], type='user' existing
- Routes: /loops/* group under 'auth' middleware
- Playwright: loginAsCpmeMember helper available, captureScreenshot helper available

Plan (7 points) validated by user. Go for implementation.

## 2026-05-16 07:20:00 Europe/Paris

Implementation complete.

Actions executed:
1. LoopMessageService: added sendHelpRequestMessage() + refactored assertCanSend()
2. AppServiceProvider: bound AiProvider::class → FakeAIProvider::class
3. LoopController: added analyzeHelpIntention() + publishHelpRequest() with full server-side validation
4. Routes: POST /loops/{loop}/help-request/analyze + POST /loops/{loop}/help-request/publish (both root + community prefix)
5. show.blade.php: full help request flow (trigger → intention input → FakeAIProvider preview → editable fields → publish) + help_request card rendering in message stream
6. LoopHelpRequestTest: 19 feature tests (service, analyze route, publish route, display, FakeAIProvider integration)
7. member-help-request.spec.js: Playwright spec with desktop + mobile + dark mode tests (6/6 PASS)
8. Screenshots captured: 5 files in docs/audits/T074.7-assets/

Full test suite: 514 PASS, 1151 assertions — zero regressions.
Playwright: 6/6 PASS (chromium + mobile-chrome), zero flaky.

Status: DONE
Lock: UNLOCKED
CI: not yet verified (no push)

## 2026-05-16 08:00:00 Europe/Paris

OPS finalization:

- Urgency fix: added `$urgency` parameter to `sendHelpRequestMessage()`, stored in metadata (OPENAI note #1 resolved)
- TASK checklist completed (all [x])
- OPENAI PASS_WITH_NOTES documented with 4 notes in AI Review section
- Full test suite: 514 PASS, 1151 assertions — zero regressions
- npm run build PASS (verified in CODE phase)
- Playwright desktop/mobile/dark PASS (6/6)
- pr.status set to READY
- Ready for check-task.sh → finalize-task.sh → merge-task.sh

# Handoffs

# Tests

- [x] feature tests — 19 tests, 63 assertions (LoopHelpRequestTest)
- [x] browser validation — 6/6 Playwright PASS (chromium + mobile-chrome)
- [x] responsive validation — mobile-chrome PASS
- [x] console inspection — one 500 resource error (unrelated, asset failed to load on server side)
- [x] tenant validation — covered by existing LoopMemberInvariantTest + controller auth checks

---

# Test Results

| Suite | Status | Details |
|-------|--------|---------|
| PHPUnit Feature Tests (LoopHelpRequestTest) | ✅ PASS | 19 tests, 63 assertions |
| PHPUnit Full Suite | ✅ PASS | 514 tests, 1151 assertions (0 regression) |
| Playwright Chromium (help request) | ✅ PASS | 3/3 (desktop, mobile, dark) |
| Playwright Mobile Chrome (help request) | ✅ PASS | 3/3 (desktop, mobile, dark) |

Screenshots: docs/audits/T074.7-assets/
- mobile-01-intention-floue.png (70K) — mobile intention input open
- mobile-02-demande-clarifiee.png (233K) — mobile preview editable after FakeAIProvider analysis
- mobile-03-demande-publiee.png (177K) — mobile help request card visible in feed
- desktop-01-loop-help-request.png (69K) — desktop published help request card
- dark-01-loop-help-request.png (174K) — dark mode with published help request

---

# AI Review

## OPENAI Review — Verdict: PASS_WITH_NOTES

| Criterion | Status |
|-----------|--------|
| Code quality | ✅ PASS |
| Test coverage | ✅ PASS |
| Playwright validation | ✅ PASS |
| Tenant safety | ✅ PASS |
| Architecture compliance | ✅ PASS |
| Migration safety | ✅ PASS |
| Breaking changes | ✅ PASS |
| **Overall** | **PASS_WITH_NOTES** |
| **Merge recommendation** | **YES** |

### Notes (non-bloquant, follow-up léger)

1. **Urgence stockée dans metadata** — Résolu OPS. `urgency` était validé dans le contrôleur mais pas transmis à `sendHelpRequestMessage()`. Corrigé : ajout du paramètre `string $urgency = 'normal'` dans le service, passage depuis le contrôleur, stockage dans `metadata['urgency']`. Aucun champ UI ajouté (hors périmètre MVP). (#1 note OPENAI)

2. **AiProvider::class → FakeAIProvider::class** — Décision volontaire et documentée. FakeAIProvider uniquement pour T074.7. Pas de provider réel nécessaire au MVP. La liaison via AppServiceProvider est propre et remplaçable. (#2 note OPENAI)

3. **Routes root `loops.*` depuis `/{community}/loops/...`** — Cohérence URL perfectible. Les routes utilisent le préfixe `/loops/` sous domaine de communauté. Non bloquant pour MVP. Le pattern existant est cohérent avec le reste de l'application. (#3 note OPENAI — follow-up)

4. **Toast global + toast local superposables** — Polish UX non bloquant. Après publication, le flash success global et les éventuels messages locaux peuvent coexister. Amélioration possible dans une tâche UI dédiée. (#4 note OPENAI — follow-up)

---

# Review Notes
- LoopMessageService::sendHelpRequestMessage() for type='help_request' with structured metadata
- FakeAIProvider bound via AppServiceProvider (AiProvider::class → FakeAIProvider::class)
- Session flash used for preview only; publishHelpRequest revalidates all fields server-side
- help_request messages rendered as amber cards in message stream, distinct from user messages
- Fallback from FakeAIProvider shows clarification prompt + pre-filled fields (user can edit)

Fichiers modifiés:
- app/Services/LoopMessageService.php — sendHelpRequestMessage() + assertCanSend() refactor
- app/Providers/AppServiceProvider.php — AiProvider bind
- app/Http/Controllers/LoopController.php — AiProvider injection, analyzeHelpIntention(), publishHelpRequest()
- routes/web.php — 2 routes x 2 (root + community prefix)
- resources/views/loops/show.blade.php — help request flow + card rendering
- tests/Feature/LoopHelpRequestTest.php — 19 tests (new file)
- tests/e2e/member-help-request.spec.js — Playwright spec (new file)

Limites connues:
- FakeAIProvider ne couvre pas tous les scénarios (fallback pour intentions non reconnues)
- Fallback scenario affiche "La phrase n'a pas pu être analysée" → l'utilisateur doit éditer manuellement
- Pas de sélecteur de Loop (publication toujours dans la Loop active)
- Pas de Reverb (broadcast log driver uniquement)
- Session flash perdue si l'utilisateur rafraîchit la page après analyse

composer changes: none
npm changes: none
migrations: none
env changes: none
queue requirements: none
cache requirements: none