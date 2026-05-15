---
task_id: TASK-074.1A
title: T074.1A — IA Solution Spike: ChatLoop assisted interactions

status: DONE

owner: OPENCODE

contributors:
  - OPENAI
  - OPENCODE

branch: T074.1A-t074-1a-ia-solution-spike-chatloop-assisted-interactions

priority: MEDIUM

created_at: 2026-05-14 22:28:00 Europe/Paris
updated_at: 2026-05-15 00:15:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: READY_FOR_REVIEW
  url: null
---

# Objective

Spike technique / solution design pour les interactions assistées par IA dans ChatLoop.

Explorer les architectures possibles, les providers, la gestion de contexte, la sécurité multi-tenant, et la viabilité technique d'une couche IA conversationnelle au sein des Loops — sans modifier le code applicatif existant.

---

# Planned Actions

- [x] inspecter l'architecture IA existante (providers, prompt builders, factories)
- [x] inspecter les mécanismes de résolution tenant / organization
- [x] inspecter le système de permissions et policies existant
- [x] documenter les options d'architecture IA pour ChatLoop
- [x] évaluer les providers IA disponibles (OpenAI, Anthropic, etc.)
- [x] évaluer la gestion de contexte par Loop
- [x] évaluer les implications multi-tenant et sécurité
- [x] rédiger le document d'audit / spike
- [x] ne modifier aucun fichier applicatif

---

# Progress Log

## 2026-05-14 22:28:00 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
T074.1A-t074-1a-ia-solution-spike-chatloop-assisted-interactions

Status:
IN_PROGRESS

This is a pure documentation / research spike.

No application code will be modified.

No migrations.
No packages installed.
No routes/controllers/models/views modified.

## 2026-05-14 23:13:42 Europe/Paris

OPENAI / Codex completed the documentation spike.

Sources inspected:

- `composer.json`
- `package.json`
- `CLAUDE.md`
- `ai/context/architecture.md`
- `ai/context/multi-tenant.md`
- `docs/01-UI_RULES.md`
- `docs/02-PRODUCT_PRINCIPLES.md`
- `docs/audits/T074.0-technical-audit-current-messaging-mobile-reverb-readiness.md`
- `app/Livewire/MessageThread.php`
- `app/Policies/MessagePolicy.php`
- `app/Http/Middleware/ResolveCommunity.php`
- `app/Http/Middleware/ResolveOrganization.php`
- `app/Support/Tenancy/CurrentOrganization.php`
- `app/Models/Scopes/BelongsToTenantScope.php`
- `routes/web.php`

External references inspected:

- `openai-php/laravel`
- `laravel/ai`
- `Aczeko/livewire-ai-chat`
- `kauffinger/livewire-chat`
- `pushpak1300/ai-chat`

Decisions documented:

- Executive decision: GO SOUS CONDITIONS.
- T074.1A remains documentation-only.
- No real AI implementation in this task.
- No package recommendation for immediate MVP implementation; prefer no-package + provider interface + `FakeAIProvider` first.
- Real AI implementation belongs in T074.7.
- Product specification and screen behavior belong in T074.2.
- ChatLoop assisted interactions must be action drafting, not chatbot UX.
- Organization remains tenant; Loop is only collaborative context.

Modified files:

- `docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md`
- `TODO/TASK-074-t074-1a-ia-solution-spike-chatloop-assisted-interactions.md`

## 2026-05-14 23:50:00 Europe/Paris

OPENCODE micro-implémentation.

Plan exact:
1. Créer `app/Services/Ai/Contracts/AiProvider.php` — interface provider-agnostique
2. Créer `app/Services/Ai/FakeAIProvider.php` — 8 scénarios déterministes
3. Créer `app/Services/Ai/DTO/AssistedInteractionLabResult.php` — DTO preview admin
4. Créer `app/Http/Controllers/Admin/AdminIaDesignLabController.php` — read-only test controller
5. Ajouter route `GET /admin/ia-design-lab` + `POST /admin/ia-design-lab/test`
6. Créer `resources/views/admin/ia-design-lab/index.blade.php` — preview UI
7. Ajouter "Lab IA" dans la sidebar admin
8. Créer `tests/Feature/Admin/AdminIaDesignLabTest.php`
9. php artisan test
10. Mettre à jour TASK

## 2026-05-15 00:15:00 Europe/Paris

OPENCODE implémentation du Lab IA admin.

Implémenté:

- `app/Services/Ai/Contracts/AiProvider.php` — interface provider-agnostique
- `app/Services/Ai/FakeAIProvider.php` — 9 scénarios déterministes (besoin client clair, demande trop vague, demande avec deadline, mauvais canal, données sensibles, loop ambiguë, intention offre, hors scope, fallback)
- `app/Services/Ai/DTO/AssistedInteractionLabResult.php` — DTO preview admin avec helpers (isHighConfidence, isLowConfidence, needsFallback, isBlocked, hasSensitiveData)
- `app/Http/Controllers/Admin/AdminIaDesignLabController.php` — read-only test controller (index + test)
- `routes/web.php` — route GET/POST /admin/ia-design-lab (sous middleware auth+admin)
- `resources/views/admin/ia-design-lab/index.blade.php` — UI complète: textarea, boutons scénario rapide, preview demande clarifiée, JSON brut, panneau fallback/safety, validation humaine, boutons désactivés "mode test"
- `resources/views/layouts/admin.blade.php` — lien "Lab IA" dans la sidebar admin
- `tests/Feature/Admin/AdminIaDesignLabTest.php` — 11 tests (accès admin, accès non-admin, accès guest, test avec phrase, 7 tests FakeAIProvider, test boutons scénario)

Fichiers modifiés:
- `routes/web.php` (+2 lignes route + 1 ligne import)
- `resources/views/layouts/admin.blade.php` (+1 ligne sidebar)
- `TODO/TASK-074-t074-1a-ia-solution-spike-chatloop-assisted-interactions.md` (progress log)

Fichiers créés:
- `app/Services/Ai/Contracts/AiProvider.php`
- `app/Services/Ai/FakeAIProvider.php`
- `app/Services/Ai/DTO/AssistedInteractionLabResult.php`
- `app/Http/Controllers/Admin/AdminIaDesignLabController.php`
- `resources/views/admin/ia-design-lab/index.blade.php`
- `tests/Feature/Admin/AdminIaDesignLabTest.php`

Non modifié:
- composer.json non modifié
- package.json non modifié
- Aucune migration
- Aucun package installé
- Aucun parcours membre modifié
- Aucune création DB
- Aucun appel externe

## 2026-05-15 00:45:00 Europe/Paris

OPENCODE — passe finale T074.1A.

Modifications:
- Garde-fou exposition: `abort_if(app()->isProduction(), 404)` dans AdminIaDesignLabController
- Sidebar: lien "Lab IA" masqué en production via `if (!app()->isProduction())`
- Tests: ajout de `test_lab_is_only_available_in_non_production_environments`
- `docs/audits/T074.1A-ia-solution-spike-chatloop-assisted-interactions.md`:
  - Section 15 "Implémentation Réalisée" ajoutée
  - Tableau "Fichiers Probables" mis à jour avec statut réel
  - Tableau comparaison UX vs Lab IA
  - Garde-fou exposition documenté
  - Assets UX de référence mentionnés
- TASK file: progress log mis à jour

Non modifié: composer.json, package.json, migrations, parcours membre, Reverb, provider IA réel.

## 2026-05-14 23:28:28 Europe/Paris

OPENAI / Codex updated the spike document with an "IA Design Lab admin" section.

Decisions documented:

- GO SOUS CONDITIONS for an internal admin IA Design Lab.
- Lab must use `FakeAIProvider` only.
- No OpenAI call, no package, no publication, no database request creation, no Reverb, no member journey modification.
- Interface is admin-only and preview/test-only.
- UI vocabulary must use "Boucle conseillee", not "cercle recommande".

Added details:

- Why the Lab helps T074.1, T074.2 and T074.7.
- Enriched JSON contract with `title`, `need`, `context`, `expected_help_type`, `deadline`, `suggested_loop`, `tone`, and `message_draft`.
- FakeAIProvider scenarios to prepare.
- Strict MVP limits.
- Probable files if CODE later implements the Lab.

No application code modified.
No dependencies modified.
No package installed.
No runtime tests executed.

## 2026-05-15 00:32:00 Europe/Paris

OPENCODE — Assets de référence UX T074.1A ajoutés.

Dossier créé :
- `docs/audits/T074.1A-assets/`

Fichiers ajoutés :
- `01-qui-peut-maider-reference.png` — référence UX : intention floue (853×1844)
- `02-demande-clarifiee-reference.png` — référence UX : demande clarifiée (853×1844)
- `README.md` — décrit le rôle des assets, distinction référence UX vs Playwright

Ces images sont des références UX fournies par Cyril.
Elles ne sont PAS des screenshots Playwright de validation.
Elles guident l'implémentation du Lab IA admin FakeAIProvider :
intention floue → demande clarifiée → Boucle conseillée → ton → validation humaine → preview action card.

Restent rangées dans `docs/audits/T074.1A-assets/`, pas dans `ai/playwright/screenshots/`.

Aucun fichier applicatif modifié.

# Handoffs

## 2026-05-14 23:13:42 Europe/Paris

Task completed and unlocked for review.

Recommended next owner action:

- Review the audit document.
- Use it as input to T074.2 product specification.
- Do not implement real AI until T074.7.

# Tests

12 tests PHPUnit ajoutés dans `tests/Feature/Admin/AdminIaDesignLabTest.php`.

---

# Test Results

```bash
php artisan test tests/Feature/Admin/AdminIaDesignLabTest.php
```

Tests: 12 passed (55 assertions)

Coverage:
- `test_guest_cannot_access_ia_design_lab` ✓
- `test_non_admin_cannot_access_ia_design_lab` ✓
- `test_admin_can_view_ia_design_lab` ✓
- `test_lab_is_only_available_in_non_production_environments` ✓
- `test_admin_can_test_with_fake_ai_provider` ✓
- `test_fake_ai_provider_returns_high_confidence_for_clear_request` ✓
- `test_fake_ai_provider_returns_low_confidence_for_vague_request` ✓
- `test_fake_ai_provider_blocks_sensitive_data` ✓
- `test_fake_ai_provider_blocks_legal_scope` ✓
- `test_fake_ai_provider_detects_deadline` ✓
- `test_fake_ai_provider_detects_offer_intent` ✓
- `test_admin_can_use_quick_scenario_buttons` ✓

All existing admin tests pass: 115 total (301 assertions).

---

# Review Notes

- ✅ IA ChatLoop remains separate from transaction messaging
- ✅ Organization = Tenant, Loop != Tenant (preserved)
- ✅ AiProvider interface is provider-agnostic
- ✅ FakeAIProvider is deterministic, cost-free, no external API
- ✅ No `composer.json` or `package.json` modified
- ✅ No Reverb touched
- ✅ No DB writes, no member journey modification
- ✅ Admin-only via existing `auth` + `admin` middleware
- ✅ UI uses "Boucle conseillée" vocabulary
- ✅ Production guard: abort_if(app()->isProduction(), 404) + sidebar masqué
- ✅ Buttons disabled mode test — no action possible
- ✅ "Rien n'est envoyé sans votre validation" displayed
- ✅ JSON brut panel for contract debugging
- ✅ Fallback/safety panel with visual checks

Limites restantes:
- Le matching de scénario par `str_contains` est simple (suffisant pour un lab interne)
- FakeAIProvider ne couvre pas tous les edge cases possibles
- Pas de screenshot Playwright (lab interne, pas de spec visuelle T074.2)
- Les boutons "Modifier", "Continuer", "Publier" sont désactivés en mode test uniquement
