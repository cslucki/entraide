# T140.5 — Master Plan
Fichier : `TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md`

Mise à jour : 2026-05-25 14:41:31 Europe/Paris

**NO-GO patch global.** Découpage en sous-tâches séquentielles.

---

## État Global

| Sous-tâche | Statut | Branche | Lock |
|------------|--------|---------|------|
| T140.5A — Channels + ResolveApiOrganization | MERGED | `TASK-144-t140-5A-channels-resolve-api-organization` | LOCKED |
| T140.5B — LoopService + LoopMessageService | IN PROGRESS | `TASK-144-t140-5B-loop-services` | UNLOCKED |
| T140.5C — ReferralService + RewardDispatcher | LOCKED | — | LOCKED |
| T140.5D — Controllers métier | LOCKED | — | LOCKED |
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

## Séquences d'exécution T140.5B

1. TECH_WRITER implémente LoopService + LoopMessageService
2. TEST_WORKER lance tests ciblés
3. STEP_GLOBAL_REVIEWER review globale
4. PROJECT_SUPERVISOR : commit, push, merge si vert

## Séquences d'exécution

1. ~~PROJECT_SUPERVISOR crée master plan + TASK file~~ ✓
2. ~~REVIEW_SUPERVISOR valide périmètre T140.5A~~ ✓
3. TECH_WRITER implémente
4. TEST_WORKER_API_CHANNELS lance tests
5. TEST_WORKER_TENANT_SAFETY audite
6. STEP_GLOBAL_REVIEWER review globale
7. REVIEW_SUPERVISOR verdict final
8. PROJECT_SUPERVISOR met à jour master plan

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
| T140.5B — LoopService + LoopMessageService | IN PROGRESS | UNLOCKED |
| T140.5C — ReferralService + RewardDispatcher | LOCKED | LOCKED |
| T140.5D — Controllers métier | LOCKED | LOCKED |
| T140.5E — Admin/Auth/Livewire cleanup | LOCKED | LOCKED |

## Décisions

- NO-GO patch global maintenu.
- T140.5A first. Sous-tâches séquentielles. Déverrouillage autonome après merge si develop propre.
- Option A stricte pour T140.5B : services uniquement, LoopController reste en T140.5D.

## Historique

- 2026-05-24 : Master plan initial. T140.5A lancé.
- 2026-05-24 : Gouvernance refondue (trackable paths, permissions, branches). Code patch channels + API effectué.
- 2026-05-24 : TECH_WRITER reste à faire (tests + audit doc).
- 2026-05-25 : T140.5A mergé. T140.5B délocké par décision gouvernance.
