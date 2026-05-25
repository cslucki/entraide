# MISSION ORCHESTRATION T140.5
Fichier : `TODO/PROJECT_SUPERVISOR/T140.5/MISSION ORCHESTRATION.md`
Mise à jour : 2026-05-25 14:41:31 Europe/Paris
Tu es OpenCode Workspace.

## Objectif

Transformer le NO-GO T140.5 global en plan multi-agents autonome, découpé en sous-tâches sûres.

## Règle absolue

Ne jamais coder T140.5 en bloc.

T140.5 est trop large pour :
- une seule PR ;
- une seule branche ;
- un seul agent ;
- un seul patch global.

Toute implémentation doit être :
- découpée ;
- supervisée ;
- reviewée ;
- testée ;
- traçable ;
- réversible.

## Architecture agents

Architecture cible :

- PRIMARY_1_PROJECT_SUPERVISOR
- PRIMARY_2_REVIEW_SUPERVISOR
- TECH_WRITER
- TEST_WORKER_API_CHANNELS
- TEST_WORKER_TENANT_SAFETY
- STEP_GLOBAL_REVIEWER

## PRIMARY_1_PROJECT_SUPERVISOR

Rôle :
chef de projet autonome.

Responsabilités :
- maintenir le plan vivant ;
- coordonner les agents ;
- maintenir les états ;
- maintenir les bloqueurs ;
- maintenir les décisions ;
- maintenir les branches prévues ;
- appliquer les règles d’autonomie ;
- décider GO / NO-GO.

Le PROJECT_SUPERVISOR ne code jamais.

Le PROJECT_SUPERVISOR ne modifie jamais :
- app/
- routes/
- tests/
- docs/

## PRIMARY_2_REVIEW_SUPERVISOR

Rôle :
assistante supervision/review.

Responsabilités :
- relire ;
- contrôler le périmètre ;
- détecter les dérives ;
- contrôler les risques ;
- rendre un verdict GO / NO-GO.

Le REVIEW_SUPERVISOR ne code jamais.

## TECH_WRITER

Rôle :
seul agent autorisé à modifier le code.

Responsabilités :
- implémentation ;
- tests ciblés ;
- documentation d’audit ;
- patch runtime.

Contraintes :
- un seul writer actif ;
- aucun élargissement de scope ;
- aucun lancement de nouvelle sous-tâche.

## TEST_WORKERS

Rôle :
agents read-only/test-only.

Responsabilités :
- validations ;
- tests ;
- audits ;
- sécurité tenant ;
- contrôle cross-organization.

Ils ne modifient pas le runtime.

## STEP_GLOBAL_REVIEWER

Rôle :
review finale globale.

Responsabilités :
- comparer plan ;
- comparer diff ;
- comparer tests ;
- comparer docs ;
- vérifier cohérence globale.

## Autorité

Le PROJECT_SUPERVISOR est le seul agent autorisé à :
- lancer des agents ;
- coordonner des agents ;
- arbitrer ;
- appliquer les règles d’autonomie ;
- décider push/merge si les règles le permettent.

Le REVIEW_SUPERVISOR :
- ne lance jamais d’agents ;
- ne modifie jamais le runtime.

Les workers :
- ne lancent jamais d’autres workers ;
- ne créent jamais de branches ;
- ne créent jamais de sous-tâches ;
- ne modifient jamais le runtime.

Le TECH_WRITER :
- ne merge jamais ;
- ne push jamais ;
- ne démarre jamais une autre sous-tâche.

---

# Task governance rules

## EPIC parent

T140.5 est un EPIC de supervision.

Le fichier parent :

`TODO/TASK-144-t140-5-runtime-organization-id.md`

NE correspond à aucune branche.

Il sert uniquement :
- de supervision globale ;
- de coordination ;
- de master task ;
- de suivi orchestration ;
- de mémoire persistante.

## Héritage obligatoire IDs

Les sous-tâches héritent obligatoirement du numéro parent :

`144`

Formats obligatoires :

```text
TASK-144-t140-5A-...
TASK-144-t140-5B-...
TASK-144-t140-5C-...
TASK-144-t140-5D-...
TASK-144-t140-5E-...
````

## Correspondance branche / taskfile

La branche doit utiliser exactement le même identifiant que le TASK file.

Exemple :

```text
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
```

→ branche :

```text
TASK-144-t140-5A-channels-resolve-api-organization
```

## Interdictions

Il est interdit :

* d’inventer un nouveau numéro TASK ;
* de créer TASK-145 ;
* de créer TASK-140 ;
* de créer une branche pour l’EPIC parent ;
* de créer une branche globale T140.5 ;
* de mélanger plusieurs sous-tâches dans une branche.

---

# Branch governance rules

## Principes

* une branche par sous-tâche ;
* jamais de branche globale T140.5 ;
* chaque sous-tâche repart de develop à jour ;
* aucune branche LOCKED ne doit exister ;
* aucune sous-tâche parallèle active.

## Branches prévues

Les branches prévues sont maintenues dans :

`TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md`

## Branches autorisées

### Branche active

```text
TASK-144-t140-5A-channels-resolve-api-organization
```

### Branches futures LOCKED

```text
TASK-144-t140-5B-loop-services
TASK-144-t140-5C-referral-reward
TASK-144-t140-5D-controllers-metier
TASK-144-t140-5E-admin-auth-livewire
```

Ces branches ne doivent pas exister tant que :

* T140.5A n’est pas MERGED ;
* les règles d’autonomie ne sont pas satisfaites ;
* une décision explicite n’a pas été prise.

---

# Orchestration file structure

## Principe

Tout artefact d’orchestration T140.5 est :

* tracké ;
* versionné ;
* synchronisable ;
* auditable ;
* persistant.

## Racine orchestration

```text
TODO/PROJECT_SUPERVISOR/T140.5/
```

## Structure

```text
TODO/PROJECT_SUPERVISOR/T140.5/
  README.md
  MISSION ORCHESTRATION.md
  MASTER_PLAN.md
  BRANCHES_PREVUES.md
  AGENTS/
    PROJECT_SUPERVISOR/
      LOG.md
      DECISIONS.md
    REVIEW_SUPERVISOR/
      REVIEW_T140.5A.md
    TECH_WRITER/
      REPORT_T140.5A.md
    TEST_WORKER_API_CHANNELS/
      REPORT_T140.5A.md
    TEST_WORKER_TENANT_SAFETY/
      REPORT_T140.5A.md
    STEP_GLOBAL_REVIEWER/
      REVIEW_T140.5A.md
```

## Interdiction _temp

Ne jamais utiliser :

```text
_temp/
```

pour :

* les plans ;
* les rapports ;
* les états ;
* les décisions ;
* les audits ;
* les workflows.

Raisons :

* non tracké ;
* gitignored ;
* non auditable ;
* non synchronisable ;
* perte d’historique.

---

# Agent read/write/delete permissions

## PROJECT_SUPERVISOR

Peut écrire uniquement :

```text
TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/*
TODO/TASK-144-t140-5-runtime-organization-id.md
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
```

Ne modifie jamais :

* app/
* routes/
* tests/
* docs/

## REVIEW_SUPERVISOR

Peut écrire uniquement :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/REVIEW_SUPERVISOR/*
```

## TECH_WRITER

Peut écrire uniquement :

```text
routes/channels.php
app/Http/Middleware/ResolveApiOrganization.php
tests dédiés API/channels
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
docs/audits/T140.5A-channels-resolve-api-organization.md
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*
```

## TEST_WORKER_API_CHANNELS

Peut écrire uniquement :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_API_CHANNELS/*
```

## TEST_WORKER_TENANT_SAFETY

Peut écrire uniquement :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_TENANT_SAFETY/*
```

## STEP_GLOBAL_REVIEWER

Peut écrire uniquement :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/STEP_GLOBAL_REVIEWER/*
```

## Suppressions autorisées

Aucun agent ne supprime un fichier sauf si explicitement listé.

Suppression autorisée :

```text
TODO/TASK-144-5A-channels-resolve-api-organization.md
```

remplacé par :

```text
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
```

---

# Subtask files expected

## Existants

### EPIC parent

```text
TODO/TASK-144-t140-5-runtime-organization-id.md
```

Pas de branche associée.

### Sous-tâche active

```text
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
```

## LOCKED mais prévus

```text
TODO/TASK-144-t140-5B-loop-services.md
TODO/TASK-144-t140-5C-referral-reward.md
TODO/TASK-144-t140-5D-controllers-metier.md
TODO/TASK-144-t140-5E-admin-auth-livewire.md
```

Ne pas créer ces fichiers sans décision explicite.

---

# Locked subtasks

Les sous-tâches suivantes sont LOCKED :

* T140.5B — LoopService + LoopMessageService
* T140.5C — ReferralService + RewardDispatcher
* T140.5D — Controllers métier
* T140.5E — Admin/Auth/Livewire cleanup

Elles restent LOCKED tant que T140.5A n’est pas :

* tests verts ;
* documentée ;
* reviewée ;
* prête merge ;
* validée humainement OU conforme aux Autonomous decision rules.

---

# Commit / push policy

## Commit

Aucun commit sans :

* rapport final TECH_WRITER ;
* review globale ;
* tests verts ;
* TASK files à jour.

## Push

Aucun push sans :

* validation humaine ;
  OU
* décision autonome conforme à `Autonomous decision rules`.

## Merge

Aucun merge si :

* scope élargi ;
* fichiers interdits ;
* changement mode parasite ;
* blockers critiques ;
* branches LOCKED créées ;
* TASK lineage incohérent.

## Hooks

Le pre-commit hook valide :

* mise à jour TASK ;
* cohérence branche ;
* cohérence task lineage.

---

# Blockers / bloqueurs

## Définition

Un bloqueur est :

* un obstacle opérationnel ;
* un problème runtime ;
* un état empêchant la suite normale.

Les bloqueurs sont :

* temporaires ;
* explicites ;
* actionnables ;
* levables.

## Ce qu’un bloqueur N’EST PAS

Un bloqueur n’est pas :

* une règle permanente ;
* une permission ;
* une convention ;
* une règle d’autonomie ;
* une règle Git ;
* une gouvernance système.

## Exemples de bloqueurs valides

* tests rouges ;
* conflit Git ;
* review NO-GO ;
* risque cross-organization ;
* périmètre violé ;
* commit sale ;
* CI rouge ;
* divergence avec MASTER_PLAN ;
* contradiction rapports agents.

## Gestion

Le PROJECT_SUPERVISOR maintient les bloqueurs actifs dans :

```text
TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
```

## Effets

Si un bloqueur critique empêche :

* commit ;
* push ;
* merge ;
* unlock sous-tâche ;
* démarrage agent ;

alors le PROJECT_SUPERVISOR doit :

* arrêter l’orchestration ;
* produire un rapport explicite ;
* demander arbitrage humain si nécessaire.

---

# Autonomous decision rules

## Principe

Le PROJECT_SUPERVISOR peut décider seul :

* push ;
* merge ;
* clôture T140.5A ;

si toutes les conditions suivantes sont vraies.

## Conditions obligatoires

* T140.5A est `DONE — PRÊT MERGE` dans `MASTER_PLAN.md` ;
* REVIEW_SUPERVISOR verdict GO ;
* STEP_GLOBAL_REVIEWER verdict GO ;
* TEST_WORKER_API_CHANNELS verdict GO ;
* TEST_WORKER_TENANT_SAFETY verdict GO ;
* le commit T140.5A existe ;
* les tests post-commit sont verts ;
* le commit est limité au périmètre T140.5A ;
* aucun fichier interdit dans le commit ;
* aucun changement de mode parasite dans le commit ;
* T140.5B/C/D/E restent LOCKED ;
* aucune branche T140.5B/C/D/E n’existe ;
* aucun TASK file T140.5B/C/D/E n’a été créé.

## Si toutes les conditions sont vraies

Le PROJECT_SUPERVISOR peut :

* push ;
* merge ;
* mettre à jour les statuts ;
* produire le rapport final.

Le PROJECT_SUPERVISOR ne peut PAS :

* démarrer T140.5B ;
* créer une branche T140.5B ;
* modifier le runtime après merge.

## Si une condition manque

Ne pas :

* push ;
* merge.

Créer :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/BLOCKED_PUSH_MERGE_T140.5A.md
```

---

# Plan maître

## Objectif global

T140.5 :
migration organization_id-first restante.

## Décision principale

NO-GO patch global.

## Découpage obligatoire

1. T140.5A — Channels + ResolveApiOrganization
2. T140.5B — LoopService + LoopMessageService
3. T140.5C — ReferralService + RewardDispatcher
4. T140.5D — Controllers métier
5. T140.5E — Admin/Auth/Livewire cleanup

## Règles

* une branche par sous-tâche ;
* une review par sous-tâche ;
* tests dédiés ;
* merge uniquement après vert ;
* aucune sous-tâche parallèle ;
* aucun mélange de périmètre.

## LOCK

Les sous-tâches :

* B ;
* C ;
* D ;
* E ;

restent LOCKED tant que :

* T140.5A n’est pas MERGED ;
* les règles autonomie ne sont pas satisfaites ;
* une décision explicite n’est pas prise.

---

# TECH_WRITER — T140.5A uniquement

## Sous-tâche active

```text
T140.5A — Channels + ResolveApiOrganization
```

## Branche

```text
TASK-144-t140-5A-channels-resolve-api-organization
```

## Périmètre autorisé

```text
routes/channels.php
app/Http/Middleware/ResolveApiOrganization.php
tests dédiés API/channels
TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
docs/audits/T140.5A-channels-resolve-api-organization.md
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*
```

## Interdits

* controllers web ;
* services ;
* Livewire ;
* referrals/rewards ;
* auth ;
* admin ;
* routes web hors channels ;
* database/* ;
* migrations/* ;
* modèles ;
* policies métier ;
* VERSION.

## Objectif

Basculer API/channels en organization_id-first,
avec fallback community_id documenté si nécessaire.

## Tests autorisés

```bash
php artisan test --filter=Channel
php artisan test --filter=Broadcast
php artisan test --filter=ResolveApiOrganization
php artisan test --filter=T1405A
```

---

# TEST_WORKER_API_CHANNELS

## Mode

read-only/test-only

## Mission

Valider :

* API/channels ;
* broadcast ;
* ResolveApiOrganization.

## Livrable

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_API_CHANNELS/REPORT_T140.5A.md
```

---

# TEST_WORKER_TENANT_SAFETY

## Mode

read-only/test-only

## Mission

Vérifier :

* absence fuite cross-org ;
* isolation tenant ;
* organization_id-first ;
* fallback legacy documenté.

## Verdict

GO uniquement si :

* aucun risque cross-organization évident.

---

# STEP_GLOBAL_REVIEWER

## Mode

read-only

## Mission

Comparer :

* MASTER_PLAN ;
* TASK file ;
* diff Git ;
* tests ;
* docs ;
* scope autorisé.

## Livrable

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/STEP_GLOBAL_REVIEWER/REVIEW_T140.5A.md
```

---

# Séquence d’exécution

1. PROJECT_SUPERVISOR crée le master plan.
2. REVIEW_SUPERVISOR valide le périmètre.
3. TECH_WRITER implémente T140.5A.
4. TEST_WORKER_API_CHANNELS lance les tests.
5. TEST_WORKER_TENANT_SAFETY vérifie tenant safety.
6. STEP_GLOBAL_REVIEWER relit diff + docs + tests.
7. REVIEW_SUPERVISOR rend verdict final.
8. PROJECT_SUPERVISOR met à jour MASTER_PLAN.md.
9. Si vert : commit.
10. Si Autonomous decision rules satisfaites :
    push + merge autorisés.
11. Après merge :
    T140.5B reste LOCKED jusqu’à validation explicite.

---

# État final attendu

* T140.5A uniquement implémenté ;
* tests verts ;
* rapports produits ;
* review globale produite ;
* MASTER_PLAN mis à jour ;
* aucun changement hors périmètre ;
* aucun push/merge sans validation humaine OU Autonomous decision rules ;
* T140.5B/C/D/E toujours LOCKED.
