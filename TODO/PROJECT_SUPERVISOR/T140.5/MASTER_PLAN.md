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
| T140.5E — Admin/Auth/Livewire cleanup | PARTIEL | — | LOCKED |
| ↳ Lot E — helpers.php | ✅ MERGED | `TASK-144-t140-5E-lotE-helpers` | LOCKED |
| ↳ Lot A — Controllers métier | IN PROGRESS | `TASK-144-t140-5E-lotA-controllers` | UNLOCKED |
| ↳ Lot B — Admin controllers | LOCKED | — | LOCKED |
| ↳ Lot C — Livewire + Views | LOCKED | — | LOCKED |
| ↳ Lot D — ResolveUrlOrganization | LOCKED | — | LOCKED |

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
| T140.5D — Controllers métier | ✅ MERGED | LOCKED |
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

⚠️ **Point de rendez-vous humain programmé** — intégration Review Cluster T140.5A-D.

## Review Cluster T140.5A-D — Conclusions

### Conflit inter-agents résolu

| Agent | Position initiale | Résolution |
|-------|-------------------|------------|
| REVIEW_ARCHITECT | Escalade — LoopMember sans tenant scope | Partialement overrulé |
| TENANT_SAFETY_REVIEWER | Pas d'escalade — bugs implementation-level | **Confirmé** |
| LARAVEL_REVIEWER | Pas d'escalade — qualité code uniquement | Confirmé |

**Arbitrage** : TENANT_SAFETY_REVIEWER correct. Audit ciblé LOOPMEMBER_TENANT_SCOPE_AUDIT confirme 10/11 faux positifs, 1 dette minimale.

### Doctrine "Guard Before Query"

Une query sans scoping SQL direct (ex: `LoopMember::where('loop_id', $loop->id)`) **n'est pas** une faille tenant si elle est précédée d'une validation explicite du `organization_id` sur l'objet parent (ex: `if ($loop->organization_id !== $orgId)`).

**Pattern validé** :
1. Charger l'objet root (Loop, Referral, etc.)
2. Valider `$objet->organization_id === $orgId` (guard amont)
3. Exécuter la query dépendante

**Absence de tenant scope SQL ≠ faille automatique.**

### Confidence levels des findings

| Niveau | Critère | Exemple |
|--------|---------|---------|
| **LOW** | grep match sans lecture contexte | LoopMember query sans where('organization_id') |
| **MEDIUM** | lecture contexte montre protection amont partielle | routes/channels.php:10 — Loop::find sans scope |
| **HIGH** | lecture contexte montre absence protection | LoopMember query sans guard amont |
| **CRITICAL** | cross-org data leak démontrable | Données d'org B visibles par org A |

**Règle** : grep finding ≠ vulnérabilité. Niveau LOW nécessite toujours lecture contexte avant escalade.

### Faux positifs confirmés

Tous les LoopMember queries identifiés sont des faux positifs. Protection existante (guard amont) suffisante :

| Location | Protection amont | Classification |
|----------|-----------------|----------------|
| routes/channels.php:16 | Lignes 25-28 | Faux positif |
| LoopService.php:47 | Lignes 41-44 | Faux positif |
| LoopService.php:72 | Lignes 66-69 | Faux positif |
| LoopMessageService.php:74 | Lignes 83-86 | Faux positif |
| LoopController.php:136 | Lignes 127-132 | Faux positif |
| loopController.php:168 | Lignes 159-164 | Faux positif |
| LoopController.php:207 | Lignes 198-203 | Faux positif |
| LoopController.php:239 | Lignes 230-235 | Faux positif |
| LoopController.php:275 | Lignes 266-271 | Faux positif |
| LoopController.php:333 | Lignes 324-329 | Faux positif |
| routes/channels.php:10 | Lignes 25-28 | Dette minimale |

Priorité 0 (LoopMember queries) **annulée** — faux positifs confirmés.

### Priorités réelles post-audit

| Priorité | Domaine | Gravité | Action |
|----------|---------|---------|--------|
| P1 | PHPStan (10 erreurs) | Moyenne | Typage Eloquent incomplet |
| P2 | `$organization_id` non déclarée | Basse | Ajouter PHPDoc User.php |
| P2B | Referral queries (3, defense-in-depth) | Basse | Scoping optionnel |
| P3 | Pint (7 violations) | Cosmétique | Corriger style |
| P4 | Configuration Rector | Info | Configurer règles |
| P5 | Documentation fallback | Info | PHPDoc pattern

## Décisions

- NO-GO patch global maintenu.
- T140.5A first. Sous-tâches séquentielles. Déverrouillage autonome après merge si develop propre.
- Option A stricte pour T140.5B : services uniquement, LoopController reste en T140.5D.
- Governance Hardening appliqué après T140.5B avant toute ouverture de T140.5C.
- T140.5D ouvert avec rendez-vous humain. Prochain rendez-vous avant T140.5E.
- T140.5E en pause — attente rapport REVIEW_CLUSTER. Pré-analyse conservée (~79 refs, 5 lots).
- **Rendez-vous humain levé pour T140.5E lots A-D** après Governance Update. Conditions d'enchaînement automatique : lot MERGED, develop propre, tests verts, tous GO, scope fixe, aucune violation, pas de finding CRITICAL reproductible, pas de modif gouvernance.
- **Doctrine "Guard Before Query" validée** : guard amont SQL + validation organization_id = protection suffisante.
- **Grep finding ≠ vulnérabilité** : confidence LOW nécessite lecture contexte avant escalade.
- **Arbitrage inter-agents** : conflit = audit ciblé obligatoire avant escalade. L'agent avec le plus de contexte sur la couche (TENANT_SAFETY_REVIEWER pour sécurité) a préséance en cas d'égalité.

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
- 2026-05-25 : T140.5E en pause — attente rapport REVIEW_CLUSTER sur T140.5A-D.
- 2026-05-25 : **Governance Update post-audit** — conflit REVIEW_ARCHITECT/TENANT_SAFETY_REVIEWER résolu (TENANT_SAFETY_REVIEWER correct). Doctrine Guard Before Query validée. Faux positifs LoopMember confirmés. Confidence levels intégrés. Priorité 0 annulée. Rendez-vous humain avant T140.5E.
- 2026-05-25 : T140.5E Lot E (helpers.php) mergé. Lots A/B/C/D LOCKED.
- 2026-05-25 : **Rendez-vous humain levé** pour enchaînement automatique T140.5E. Ordre : A → C → B → D. Conditions : lot précédent MERGED, develop propre, tests verts, tous GO, scope fixe, aucune violation, pas de finding CRITICAL reproductible, pas de modif gouvernance.
