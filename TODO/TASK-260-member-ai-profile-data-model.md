---
task_id: TASK-260
title: Member AI profile data model

status: MERGED

owner: OPENGINE

contributors: []

branch: TASK-260-member-ai-profile-data-model

priority: MEDIUM

created_at: 2026-06-11 21:55:26 Europe/Paris
updated_at: 2026-06-11 23:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer le socle data de l'agent IA de profil Member : migration + model + config + factory + tests. Sans UI, sans génération IA réelle.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] migration + model + config
- [x] factory + tests
- [x] run regression

---

# Progress Log

## 2026-06-11 21:55:26 Europe/Paris

Task created.

Owner:
OPENGINE

Branch:
TASK-260-member-ai-profile-data-model

Status:
IN_PROGRESS

## 2026-06-11 23:15:00 Europe/Paris

Implémentation terminée.

**Fichiers créés (5):**

| Fichier | Description |
|---------|-------------|
| `database/migrations/2026_06_11_150011_create_member_ai_profiles_table.php` | Table `member_ai_profiles` (rewrite) |
| `database/migrations/2026_06_11_150012_rebuild_member_ai_profiles_table.php` | Rebuild avec schéma corrigé |
| `app/Models/MemberAiProfile.php` | Model avec HasUuids, HasFactory, status constants, casts JSON, scopes, relations |
| `config/member_ai_profile.php` | Options MVP (tones, target_audience, help_types, etc.) |
| `database/factories/MemberAiProfileFactory.php` | Factory avec états published/draft |
| `tests/Feature/MemberAiProfileTest.php` | 11 tests (29 assertions) |

**Schéma table member_ai_profiles:**
- UUID PK, org_id FK (cascade), user_id FK (cascade)
- UNIQUE(org_id, user_id)
- Colonnes dédiées: status (draft→published→disabled), locale (fr), member_profile_summary, service_scope, experience_context, preferred_contact_action, tone, generated_summary, validated_at, last_saved_at
- JSON: target_audience, problems_helped, skills, help_types, boundaries, good_request_examples, bad_request_examples, wizard_state, metadata

**11 tests:** draft creation, unique(org,user), cross-org profiles, org isolation scope, user ownership scope, status transitions, JSON casts, published scope, belongsTo relations, cascade delete (org forceDelete, user delete)

**Régression: 292/292 (903 assertions)** — 0 échec

# Handoffs

# Tests

- [x] feature tests — 11 MemberAiProfile + 259 Admin + 19 LoopHelpRequest + 14 ClarifyUserHelpRequest + 6 AiBenchmarkPrompt = 292/292

# Test Results

```
MemberAiProfileTest:  11 passed (29 assertions)
Admin/:               259 passed (739 assertions)
LoopHelpRequestTest:   19 passed (59 assertions)
ClarifyUserHelp...:    14 passed (82 assertions)
AiBenchmarkPromptTest:  6 passed (16 assertions)
Total: 292 passed (903 assertions)
```

---

# Review Notes

Key decisions:
- `Organization` uses `SoftDeletes` — FK cascade only triggers on `forceDelete()`, not standard `delete()`. Test uses `forceDelete()`.
- 6 statuses: draft, ready_for_generation, generated, pending_validation, published, disabled
- JSON fields use native `->nullable()` (no default `'[]'::jsonb`) for clean wizard_state and metadata semantics
- Config options stored in `config/member_ai_profile.php` (not DB) — MVP options exported from design doc 02-Agent-profil-IA.md

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
