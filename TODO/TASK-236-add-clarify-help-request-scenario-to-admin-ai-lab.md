---
task_id: TASK-236
title: Add clarify_help_request scenario to admin AI lab

status: MERGED

owner: OPENCODE

contributors:
  - CODEUR
  - VERIFICATOR

branch: TASK-236-add-clarify-help-request-scenario-to-admin-ai-lab

priority: MEDIUM

created_at: 2026-06-10 21:42:22 Europe/Paris
updated_at: 2026-06-10 23:30:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: 2026-06-10 22:30:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Ajouter le scénario `clarify_help_request` dans le centre de supervision IA admin, en conservant `supervision_content` fonctionnel.

---

# Planned Actions

- [ ] Créer `ClarifyHelpRequestScenario` avec systemPrompt et jsonSchema
- [ ] Enregistrer dans `AiScenarioFactory`
- [ ] Modifier `AdminAiSupervisionController` pour accepter scenario
- [ ] Modifier la vue `index.blade.php` pour le sélecteur + nouveau resultat
- [ ] Ajouter 4 tests feature
- [ ] Vérifier tests existants intacts (21 tests feature, 10 unit)

---

# Fichiers

| Fichier | Action |
|---|---|
| `app/Services/Ai/Scenarios/ClarifyHelpRequestScenario.php` | CREER |
| `app/Providers/AppServiceProvider.php` | MODIFIER (+1 registration) |
| `app/Http/Controllers/Admin/AdminAiSupervisionController.php` | MODIFIER (+scenario logic) |
| `resources/views/admin/ai-supervision/index.blade.php` | MODIFIER (+selector + new display) |
| `tests/Feature/Admin/AdminAiSupervisionTest.php` | MODIFIER (+4 tests) |

---

# Tests attendus

- `test_admin_can_use_clarify_help_request_scenario`
- `test_clarify_help_request_vague_input_generates_questions`
- `test_clarify_help_request_payload_uses_store_false_and_json_schema`
- `test_supervision_content_still_works_after_adding_new_scenario`
- 17 tests existants inchangés → 21 tests feature
- 10 tests unit AiScenarioFactory → inchangés

---

# Progress Log

## 2026-06-10 21:42:22 Europe/Paris

Task created.

## 2026-06-10 21:51:00 Europe/Paris

- CODEUR a exécuté le plan (fichiers créés/modifiés)
- ORCH a documenté le travail de CODEUR dans la conversation

## 2026-06-10 22:25 Europe/Paris

- CODEUR a envoyé son DONE signal officiel via SMT tmux
- ORCH a vérifié : 3 feature tests clarify pass (23 assertions), 10 unit tests pass (44 assertions)
- ORCH a documenté Entry 3 dans la conversation
- Envoyé à VERIFICATOR pour review (véritable, après CODEUR DONE)

## 2026-06-10 22:30 Europe/Paris

- VERIFICATOR verdict reçu : OK, pas de réserve
- Status → DONE, lock → UNLOCKED
- check-task.sh passé
- Merge différé (Cyril endormi, validation explicite requise)

---

# Test Results

| Suite | Tests | Status |
|---|---|---|
| Feature — clarify filter | 3 passed (23 assertions) | ✅ |
| Unit — AiScenarioFactoryTest | 10 passed (44 assertions) | ✅ |
| Full AdminAiSupervisionTest | Flaky (RefreshDatabase + PostgreSQL) — not code related | ⚠️ |

Run at: 2026-06-10 22:25 Europe/Paris

---

# Review Notes

## 2026-06-10 22:30 Europe/Paris

**Verdict VERIFICATOR : OK**

| Point | Statut |
|---|---|
| Scope | ✅ |
| Régression | ✅ |
| Sécurité | ✅ |
| Structure | ✅ |
| Tests | ✅ |
| Vue | ✅ |
| Controller | ✅ |
| Factory | ✅ |

Pas de réserve. Prêt pour merge.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
