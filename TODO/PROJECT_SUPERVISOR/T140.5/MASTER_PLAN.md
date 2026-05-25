# T140.5 — Master Plan
Fichier : `TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md`

Mise à jour : 2026-05-25 14:41:31 Europe/Paris

**NO-GO patch global.** Découpage en sous-tâches séquentielles.

---

## État Global

| Sous-tâche | Statut | Branche | Lock |
|------------|--------|---------|------|
| T140.5A — Channels + ResolveApiOrganization | MERGED | `TASK-144-t140-5A-channels-resolve-api-organization` | LOCKED |
| T140.5B — LoopService + LoopMessageService | ✅ MERGED | `TASK-144-t140-5B-loop-services` | LOCKED |
| T140.5C — ReferralService + RewardDispatcher | ✅ MERGED | `TASK-144-t140-5C-referral-reward` | LOCKED |
| T140.5D — Controllers métier | ✅ MERGED | `TASK-144-t140-5D-controllers-metier` | LOCKED |
| T140.5E — Admin/Auth/Livewire cleanup | LOCKED | — | LOCKED |

## Périmètre T140.5A

**Autorisé :**
- `routes/channels.php`
- `app/Http/Middleware/ResolveApiOrganization.php`
- Tests dédiés API/channels
- `TODO/TASK-144-t140-5A-channels-resolve-api-organization.md`
- `docs/audits/T140.5A-channels-resolve-api-organization.md`
- `TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*`

**Interdit :**
- controllers web, services, Livewire, referrals/rewards
- auth, admin, routes web hors channels
- database/*, migrations/*
- modèles, policies métier, VERSION

## Périmètre T140.5B

**Autorisé :**
- `app/Services/LoopService.php`
- `app/Services/LoopMessageService.php`
- `app/Http/Controllers/LoopController.php` *(lecture uniquement — compréhension dépendances)*
- Tests strictement nécessaires pour couvrir les services
- `TODO/TASK-144-t140-5B-loop-services.md`
- `docs/audits/T140.5B-loop-services.md`
- `TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*`

**Interdit :**
- `app/Http/Controllers/LoopController.php` *(modification)*
- controllers web, Livewire, referrals/rewards
- auth, admin, database/*, migrations/*
- modèles, policies métier, VERSION
- T140.5C/D/E

## Périmètre T140.5C

**Autorisé :**
- `app/Services/ReferralService.php`
- `app/Services/RewardDispatcher.php`
- `tests/Feature/RewardDispatcherTest.php`
- `tests/Feature/ReferralServiceTest.php` *(lecture uniquement)*
- `TODO/TASK-144-t140-5C-referral-reward.md`
- `docs/audits/T140.5C-referral-reward.md`
- `TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*`

**Interdit :**
- `app/Models/PointLedger.php`
- `app/Http/Controllers/Admin/AdminReferralController.php`
- `app/Http/Controllers/LoopController.php`
- `database/*`, `migrations/*`
- modèles, policies, VERSION
- T140.5D/E

## Séquences d'exécution T140.5C

1. TECH_WRITER implémente ReferralService + RewardDispatcher
2. TEST_WORKER valide + corrige RewardDispatcherTest
3. STEP_GLOBAL_REVIEWER review globale
4. REVIEW_SUPERVISOR verdict final
5. PROJECT_SUPERVISOR : commit, push, merge si vert
6. Point de rendez-vous humain avant T140.5D

## Séquences d'exécution

1. ~~PROJECT_SUPERVISOR crée master plan + TASK file~~ ✓
2. ~~REVIEW_SUPERVISOR valide périmètre T140.5A~~ ✓
3. TECH_WRITER implémente
4. TEST_WORKER_API_CHANNELS lance tests
5. TEST_WORKER_TENANT_SAFETY audite
6. STEP_GLOBAL_REVIEWER review globale
7. REVIEW_SUPERVISOR verdict final
8. PROJECT_SUPERVISOR met à jour master plan

## Périmètre T140.5D

**Autorisé :**
- `app/Http/Controllers/LoopController.php`
- Tests strictement nécessaires associés au controller
- `TODO/TASK-144-t140-5D-controllers-metier.md`
- `docs/audits/T140.5D-controllers-metier.md`

**Interdit :**
- services déjà mergés T140.5B/T140.5C
- admin, auth, Livewire
- database/*, migrations/*
- modèles, policies métier, VERSION
- T140.5E

## Séquences d'exécution T140.5D

1. TECH_WRITER implémente LoopController
2. TEST_WORKER lance tests ciblés
3. STEP_GLOBAL_REVIEWER review
4. REVIEW_SUPERVISOR verdict
5. PROJECT_SUPERVISOR : commit, push, merge
6. **Rendez-vous humain obligatoire avant T140.5E**

## Bloqueurs

- Aucun pour l'instant.

### Décision autonome push/merge

Le PROJECT_SUPERVISOR est autorisé à décider seul le push + merge d'une sous-tâche T140.5 si toutes les conditions de :

`MISSION ORCHESTRATION.md` → `8. AUTONOMOUS DECISION RULES`

sont satisfaites. Aucun GO humain requis après validation initiale.

### Statuts

| Sous-tâche | Statut | Lock |
|---|---|---|
| T140.5A — Channels + ResolveApiOrganization | ✅ MERGED | LOCKED |
| T140.5B — LoopService + LoopMessageService | ✅ MERGED | LOCKED |
| T140.5C — ReferralService + RewardDispatcher | ✅ MERGED | LOCKED |
| T140.5D — Controllers métier | IN PROGRESS | UNLOCKED |
| T140.5E — Admin/Auth/Livewire cleanup | LOCKED | LOCKED |

## Governance Hardening — Leçons T140.5A/T140.5B

### Règles renforcées

1. **PROJECT_SUPERVISOR ne code jamais** — ni app/, ni routes/, ni tests/, ni docs/. Violation observée dans T140.5B (première tentative), corrigée.
2. **Orchestration multi-agents obligatoire** pour chaque sous-tâche : TECH_WRITER → TEST_WORKER → STEP_GLOBAL_REVIEWER → REVIEW_SUPERVISOR → PROJECT_SUPERVISOR.
3. **Points de rendez-vous humains obligatoires** : après T140.5B, après T140.5C, après T140.5D, et avant tout changement de phase métier.
4. **Enchaînement automatique autorisé si** : sous-tâche MERGED, develop propre, tous agents GO, rendez-vous suivant déjà validé.
5. **Retour humain obligatoire si** : changement de phase, incertitude scope, conflit critique, violation périmètre, bloqueur runtime.
6. **Modification gouvernance = rendez-vous humain obligatoire** : toute modification de MASTER_PLAN.md, MISSION ORCHESTRATION.md, règles d'autonomie/orchestration/doctrine impose un arrêt et une validation humaine avant toute poursuite.

### Prochain rendez-vous

⚠️ **Point de rendez-vous humain programmé** — après merge T140.5D, avant T140.5E.

## Décisions

- NO-GO patch global maintenu.
- T140.5A first. Sous-tâches séquentielles. Déverrouillage autonome après merge si develop propre.
- Option A stricte pour T140.5B : services uniquement, LoopController reste en T140.5D.
- Governance Hardening appliqué après T140.5B avant toute ouverture de T140.5C.
- T140.5D ouvert avec rendez-vous humain. Prochain rendez-vous avant T140.5E.

## Historique

- 2026-05-24 : Master plan initial. T140.5A lancé.
- 2026-05-24 : Gouvernance refondue (trackable paths, permissions, branches). Code patch channels + API effectué.
- 2026-05-24 : TECH_WRITER reste à faire (tests + audit doc).
- 2026-05-25 : T140.5A mergé. T140.5B délocké par décision gouvernance.
- 2026-05-25 : T140.5B implémenté, testé (221 pass), mergé.
- 2026-05-25 : Governance Hardening — règles renforcées, rendez-vous humain avant T140.5C.
- 2026-05-25 : T140.5C implémenté, testé (98 pass), mergé.
- 2026-05-25 : T140.5D ouvert (GO humain). Début orchestration.
- 2026-05-25 : T140.5D mergé (826 pass). Rendez-vous gouvernance avant T140.5E.
