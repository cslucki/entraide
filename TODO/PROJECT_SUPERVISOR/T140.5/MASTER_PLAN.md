# T140.5 — Master Plan
Fichier : `TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md`

Mise à jour : 2026-05-25 14:41:31 Europe/Paris

**NO-GO patch global.** Découpage en sous-tâches séquentielles.

---

## État Global

| Sous-tâche | Statut | Branche | Lock |
|------------|--------|---------|------|
| T140.5A — Channels + ResolveApiOrganization | DONE — PRÊT MERGE | `TASK-144-t140-5A-channels-resolve-api-organization` | UNLOCKED |
| T140.5B — LoopService + LoopMessageService | LOCKED | — | LOCKED |
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

Le PROJECT_SUPERVISOR est autorisé à décider seul le push + merge de T140.5A si toutes les conditions de :

`MISSION ORCHESTRATION.md` → `8. AUTONOMOUS DECISION RULES`

sont satisfaites.

Statut attendu après merge :

| Sous-tâche | Statut attendu | Lock |
|---|---|---|
| T140.5A — Channels + ResolveApiOrganization | MERGED | LOCKED |
| T140.5B — LoopService + LoopMessageService | LOCKED | LOCKED |
| T140.5C — ReferralService + RewardDispatcher | LOCKED | LOCKED |
| T140.5D — Controllers métier | LOCKED | LOCKED |
| T140.5E — Admin/Auth/Livewire cleanup | LOCKED | LOCKED |

## Décisions

- NO-GO patch global maintenu.
- T140.5A first. Les autres sous-tâches LOCKED.
- Chaque sous-tâche repart de develop à jour.

## Historique

- 2026-05-24 : Master plan initial. T140.5A lancé.
- 2026-05-24 : Gouvernance refondue (trackable paths, permissions, branches). Code patch channels + API effectué.
- 2026-05-24 : TECH_WRITER reste à faire (tests + audit doc).
