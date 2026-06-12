---
task_id: TASK-263
title: Affichage agent IA sur fiche membre

status: IN_PROGRESS

owner: CODEUR

contributors: []

branch: TASK-263-affichage-agent-ia-sur-fiche-membre

priority: MEDIUM

created_at: 2026-06-12 10:08:37 Europe/Paris
updated_at: 2026-06-12 10:08:37 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: CODEUR
  since: 2026-06-12 10:08:37 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Afficher une carte "Agent IA de profil" sur la fiche membre (`/profile/{user}`) quand MemberAiProfile est publié. Mini Q&A inline embarqué (pas de redirection). Réponse bornée rule-based (réutilise BoundedMemberScenario).

---

# Files

| File | Action |
|------|--------|
| `app/Livewire/InlineMemberAgent.php` | Nouveau — composant Livewire embarqué |
| `resources/views/livewire/inline-member-agent.blade.php` | Nouveau — vue carte + Q&A |
| `resources/views/profile/show.blade.php` | Modifié — `@livewire('inline-member-agent', ['user' => $user])` |
| `app/Http/Controllers/ProfileController.php` | Modifié — passe $user avec relation MemberAiProfile |
| `tests/Feature/Livewire/InlineMemberAgentTest.php` | Nouveau — tests feature |
| `tests/e2e/inline-member-agent.spec.js` | Nouveau — test Playwright |

# Rules

- Carte visible uniquement si MemberAiProfile::status === 'published'
- Mini Q&A : réutilise BoundedMemberScenario (matchQuestion) ou sa logique
- Pas d'historique / pas de conversation persistante
- Pas de marketplace
- Fallback si profil non publié : carte invisible (rien)
- Réponse bornée rule-based (comme T262)
- Design card : fond white/gray, bords arrondis, tons calmes (pas rouge agressif, pas branding IA lourd)

# Validation

- PHPUnit : InlineMemberAgentTest ≥ 8 tests
- Playwright : ≥ 2 tests
- Console : 0 erreurs
- DB : bouclepro_test safe

# Planned Actions

- [x] inspect architecture (fait par ORCH)
- [x] create InlineMemberAgent.php Livewire component
- [x] create inline-member-agent.blade.php view
- [x] modify profile/show.blade.php to embed @livewire
- [x] modify ProfileController.php to pass user data
- [x] write PHPUnit tests
- [x] write Playwright tests
- [x] run regression
- [x] validate UI (console errors, responsive)

---
# Progress Log

## 2026-06-12 10:08:37 Europe/Paris

Task created.

Owner: CODEUR
Branch: TASK-263-affichage-agent-ia-sur-fiche-membre
Status: IN_PROGRESS

## 2026-06-12 10:15:00 Europe/Paris

CODEUR implementation complete:

- InlineMemberAgent.php created — Livewire component inline (no layout), rule-based Q&A
- inline-member-agent.blade.php created — compact card with summary, skills badges, mini Q&A
- ProfileController.php modified — loads $memberAiProfile relation
- profile/show.blade.php modified — embeds @livewire('inline-member-agent') only for other users' profiles
- InlineMemberAgentTest.php — 13 tests, 29 assertions, all pass
- inline-member-agent.spec.js — 2 Playwright tests, all pass
- Regression: BoundedMemberAgentTest (10) + AdminAiSupervisionTest (48) = 58 passed, 213 assertions

# Handoffs

## 2026-06-12 10:15:00 Europe/Paris — CODEUR → ORCH

SMT sent via tmux. Conversation updated.

# Tests

- [x] feature tests (13 passed, 29 assertions)
- [x] browser validation (Playwright 2/2 passed)
- [x] responsive validation (compact card, inline layout)
- [x] console inspection (0 errors)
- [x] tenant validation (3-level org fallback, org scoping in controller)

---

# Test Results

2026-06-12 10:15 Europe/Paris

- `InlineMemberAgentTest`: 13 passed, 29 assertions, 1.88s
- `BoundedMemberAgentTest` regression: 10 passed, 26 assertions
- `AdminAiSupervisionTest` regression: 48 passed, 187 assertions
- Total regression: 58 passed, 213 assertions, 4.18s
- Playwright `inline-member-agent.spec.js`: 2 passed, 4.4s
- DB preflight: `bouclepro_test` — safe

---

# Review Notes

- VERIFICATOR must confirm: card hidden when no profile / not published
- VERIFICATOR must confirm: card hidden on own profile (auth check)
- VERIFICATOR must confirm: rule-based only, no LLM calls
- VERIFICATOR must confirm: interaction logging in admin_ai_interactions with scenario_id=inline_member_presentation
- VERIFICATOR must confirm: 3-level org fallback present
- VERIFICATOR must confirm: no files outside scope (6 files created/modified)

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`