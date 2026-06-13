---
task_id: TASK-263
title: Affichage agent IA sur fiche membre — Loop privé par visiteur

status: MERGED

owner: CODEUR

contributors:
  - ORCH

branch: TASK-263-affichage-agent-ia-sur-fiche-membre

priority: MEDIUM

created_at: 2026-06-12 10:08:37 Europe/Paris
updated_at: 2026-06-12 12:11:00 Europe/Paris

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

Afficher une carte "Agent IA" sur la fiche membre (`/profile/{user}`) quand `MemberAiProfile` est publié. Pour les visiteurs authentifiés : création d'une Boucle privée par paire (visiteur, profil) avec réponses IA automatiques. Pour les invités : fallback `InlineMemberAgent` (Q&A rule-based embarqué).

---

# Files

| File | Action |
|------|--------|
| `database/migrations/2026_06_12_121500_add_member_ai_profile_id_to_loops_table.php` | Nouveau |
| `app/Models/Loop.php` | Modifié — `memberAiProfile()`, `isAiAgent()`, `$fillable` |
| `app/Models/MemberAiProfile.php` | Modifié — `loops()` HasMany |
| `app/Http/Controllers/AiAgentLoopController.php` | Nouveau — startConversation |
| `app/Jobs/GenerateAiAgentResponse.php` | Nouveau — réponse IA async |
| `app/Providers/AppServiceProvider.php` | Modifié — listener LoopMessageCreated |
| `app/Services/Ai/MemberProfileAgentResponder.php` | Nouveau — responder LLM |
| `app/Models/MemberAiProfileInteraction.php` | Nouveau |
| `database/migrations/2026_06_12_120000_create_member_ai_profile_interactions_table.php` | Nouveau |
| `resources/views/profile/show.blade.php` | Modifié — card Loop privé |
| `app/Http/Controllers/ProfileController.php` | Modifié — passe $memberAiProfile |
| `app/Http/Controllers/MemberAiProfileInteractionController.php` | Nouveau |
| `resources/views/agent-ia/interactions.blade.php` | Nouveau |
| `routes/web.php` | Modifié — routes agent-ia |
| `tests/Feature/AiAgentLoopControllerTest.php` | Nouveau — 6 tests |
| `tests/Feature/MemberAiProfileInteractionTest.php` | Nouveau — 5 tests |
| `app/Livewire/InlineMemberAgent.php` | Modifié — fallback guest |
| `tests/Feature/Livewire/InlineMemberAgentTest.php` | Modifié — 14 tests |
| `tests/e2e/inline-member-agent.spec.js` | Modifié |
| `app/Http/Controllers/Admin/AdminMemberAiProfileController.php` | Nouveau |
| `resources/views/admin/member-ai-profiles/*` | Nouveau |
| `resources/views/layouts/admin.blade.php` | Modifié |
| `resources/js/app.js` | Modifié |
| `resources/css/app.css` | Modifié |
| `tests/Feature/Admin/MemberAiProfileAdminTest.php` | Nouveau |
| `tests/e2e/admin-member-ai-profiles.spec.js` | Nouveau |

# Rules

- Auth visitor : Loop privé (type=`ai_agent`, visibility=`private`) par paire (visiteur, profil)
- Réponses IA générées via LLM configuré (Ollama/OpenRouter) avec fallback rule-based
- Owner du profil : "Agent IA activé" + lien "Voir les échanges"
- Guest : fallback InlineMemberAgent (Q&A rule-based inline, pas de Loop)
- Carte invisible si MemberAiProfile non publié
- Carte visible sur son propre profil si publié
- Pas de marketplace, pas de matching, pas de messages privés

# Validation

- PHPUnit : 33 tests, 80 assertions (4 test files)
- Playwright : 5 tests (admin-member-ai-profiles + inline-member-agent)
- Console : 0 erreurs
- DB : bouclepro_test safe

---

# Progress Log

## 2026-06-12 10:08:37 Europe/Paris

Task created. InlineMemberAgent approach.

## 2026-06-12 10:15 — 11:18 Europe/Paris

Initial InlineMemberAgent + admin extension completed.

## 2026-06-12 11:45 — 12:11 Europe/Paris

Loop-based approach implemented (CYRIL rejected InlineMemberAgent for auth):

- Migration `add_member_ai_profile_id_to_loops_table` created and run
- AiAgentLoopController: private Loop per (visitor, profile) paire
- GenerateAiAgentResponse job + MemberProfileAgentResponder
- LoopMessageCreated listener registered in AppServiceProvider
- profile/show.blade.php: Loop card for auth, InlineMemberAgent for guests
- Tests: AiAgentLoopControllerTest (6), InlineMemberAgentTest fixed (14)
- Full regression: 33 PHPUnit + 5 Playwright, all green

# Tests

- [x] AiAgentLoopControllerTest: 6 passed, 10 assertions
- [x] InlineMemberAgentTest: 14 passed, 30 assertions
- [x] MemberAiProfileAdminTest: 8 passed, 30 assertions
- [x] MemberAiProfileInteractionTest: 5 passed, 10 assertions
- [x] Playwright admin-member-ai-profiles: 3 passed
- [x] Playwright inline-member-agent: 2 passed
- [x] DB preflight: bouclepro_test — safe
- [x] Migration: fresh run OK

---

# Test Results

2026-06-12 12:11 Europe/Paris

- 33 PHPUnit tests passed, 80 assertions, 3.52s
- 5 Playwright tests passed
- DB preflight: bouclepro_test — safe
- Migration: migrate:fresh — seed OK

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
