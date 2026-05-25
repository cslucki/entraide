# MISSION ORCHESTRATION T140.5
Fichier : `TODO/PROJECT_SUPERVISOR/T140.5/MISSION ORCHESTRATION.md`
Mise à jour : 2026-05-25 Governance Hardening — règles renforcées, orchestration multi-agents obligatoire, rendez-vous humains
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

Architecture agents (subtask-agnostic) :

- PRIMARY_1_PROJECT_SUPERVISOR
- PRIMARY_2_REVIEW_SUPERVISOR
- TECH_WRITER (un seul par sous-tâche)
- TEST_WORKER (cellule générique : tests unitaires + tenant safety)
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

### Branches actives

Aucune — point de rendez-vous actif.

### Branches LOCKED à venir

```text
TASK-144-t140-5C-referral-reward
TASK-144-t140-5D-controllers-metier
TASK-144-t140-5E-admin-auth-livewire
```

Ces branches ne doivent pas exister tant qu’une décision explicite (humaine ou autonome) n’a pas été prise pour chaque sous-tâche.

### Branches historiques (MERGED)

```text
TASK-144-t140-5A-channels-resolve-api-organization ✅ MERGED
TASK-144-t140-5B-loop-services                      ✅ MERGED
```

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

Le PROJECT_SUPERVISOR peut décider seul le push + merge d'une sous-tâche T140.5 si toutes les conditions suivantes sont vraies.

Ce document définit les règles pour toutes les sous-tâches (A, B, C, D, E).

## Conditions obligatoires (toute sous-tâche)

* la sous-tâche est `DONE — PRÊT MERGE` dans `MASTER_PLAN.md` ;
* REVIEW_SUPERVISOR verdict GO ;
* STEP_GLOBAL_REVIEWER verdict GO ;
* TEST_WORKER verdict GO ;
* le commit de la sous-tâche existe ;
* les tests post-commit sont verts ;
* le commit est limité au périmètre autorisé de la sous-tâche ;
* aucun fichier interdit dans le commit ;
* develop est propre (aucun changement non commité, aucune sale branche) ;
* les sous-tâches suivantes restent LOCKED (sauf si règles d'enchaînement) ;
* aucune branche des sous-tâches suivantes n'existe.

## Règles d'enchaînement autonome

Le PROJECT_SUPERVISOR peut enchaîner automatiquement sur la sous-tâche suivante SI :

* sous-tâche courante MERGED ✅ ;
* develop propre ;
* aucun bloqueur actif ;
* le MASTER_PLAN ne spécifie PAS de point de rendez-vous humain entre les deux sous-tâches.

## Conditions de retour humain obligatoire

Le PROJECT_SUPERVISOR DOIT marquer un point de rendez-vous et attendre validation humaine si :

* changement de phase métier (ex: services → controllers, ou controllers → admin) ;
* incertitude sur le périmètre d'une sous-tâche ;
* conflit Git irrésoluble ;
* violation de périmètre détectée ;
* bloqueur runtime non résoluble ;
* divergence entre rapports d'agents (ex: TECH_WRITER GO mais TEST_WORKER NO-GO) ;
* le superviseur humain (Cyril) l'exige explicitement.

## Si toutes les conditions sont vraies

Le PROJECT_SUPERVISOR peut :

* push ;
* merge ;
* mettre à jour les statuts ;
* produire le rapport final.

Le PROJECT_SUPERVISOR ne peut PAS :

* lancer la sous-tâche suivante si un point de rendez-vous est actif ;
* modifier le runtime après merge ;
* sauter une étape d'orchestration.

## Si une condition manque

Ne pas :

* push ;
* merge ;
* unlock sous-tâche suivante.

Créer un rapport dans :

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/BLOCKED_<SUBTASK>.md
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

# Séquence d’exécution (subtask-agnostic)

1. PROJECT_SUPERVISOR crée le master plan + TASK file.
2. REVIEW_SUPERVISOR valide le périmètre.
3. TECH_WRITER implémente la sous-tâche.
4. TEST_WORKER lance les tests ciblés + tenant safety.
5. STEP_GLOBAL_REVIEWER relit diff + docs + tests.
6. REVIEW_SUPERVISOR rend verdict final.
7. PROJECT_SUPERVISOR met à jour MASTER_PLAN.md.
8. Si vert : commit.
9. Si Autonomous decision rules satisfaites : push + merge.
10. Si un point de rendez-vous humain est actif : STOP, attendre validation.
11. Sinon : enchaînement autonome sur sous-tâche suivante.

## Types de TEST_WORKER par sous-tâche

| Sous-tâche | Focus tests |
|------------|-------------|
| T140.5A | API/channels + broadcast + tenant safety |
| T140.5B | LoopService + LoopMessageService + tenant safety |
| T140.5C | ReferralService + RewardDispatcher + tenant safety |
| T140.5D | LoopController (routes web, policies, Livewire) + tenant safety |
| T140.5E | Admin/Auth/Livewire cleanup + tenant safety |

---

### TEST_WORKER generic

## Mode

read-only/test-only.

Peut être spécialisé par sous-tâche (ex: TEST_WORKER_API_CHANNELS, TEST_WORKER_T140.5B, etc.).

## Mission

Valider :
* tests ciblés de la sous-tâche ;
* absence de régressions ;
* tenant safety (cross-org isolation) ;
* cohérence avec les sous-tâches précédentes mergées.

## Livrable

```text
TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_<SUBTASK>/REPORT_<SUBTASK>.md
```

---

# Governance Hardening

## Origine

Le comportement observé lors de T140.5B a révélé une dérive : le PROJECT_SUPERVISOR a commencé à coder directement au lieu de déléguer au TECH_WRITER via sous-agent.

Cette section fige la correction.

## Règles absolues

1. **PROJECT_SUPERVISOR ne code jamais.** Il ne modifie jamais :
   - app/
   - routes/
   - tests/
   - docs/

2. **PROJECT_SUPERVISOR ne teste jamais.** Il orchestre, coordonne, décide, merge — mais n'exécute pas de tests lui-même.

3. **PROJECT_SUPERVISOR ne review pas.** La revue est déléguée à STEP_GLOBAL_REVIEWER + REVIEW_SUPERVISOR.

4. **Orchestration multi-agents obligatoire.** Une sous-tâche sans TECH_WRITER/TEST_WORKER/STEP_GLOBAL_REVIEWER/REVIEW_SUPERVISOR explicites est invalide.

5. **Les sous-agents sont exécutés via `task` tool**, pas par le PROJECT_SUPERVISOR lui-même.

## Cycle de sous-tâche complet et obligatoire

```text
TECH_WRITER (implémente)
  → TEST_WORKER (tests + tenant safety)
    → STEP_GLOBAL_REVIEWER (revue périmètre)
      → REVIEW_SUPERVISOR (verdict final)
        → PROJECT_SUPERVISOR (commit + push + merge)
```

Aucune étape ne peut être sautée.

## Règle : rendez-vous humain obligatoire après modification gouvernance

### Quand

Après toute modification de :
- `MASTER_PLAN.md`
- `MISSION ORCHESTRATION.md`
- règles d’autonomie
- règles d’orchestration
- doctrine multi-agents
- règles merge/unlock

### Pourquoi

Ces fichiers définissent le comportement du système autonome lui-même. Ils doivent être validés humainement avant poursuite.

### Effet

Aucune nouvelle branche, aucun unlock, aucune nouvelle orchestration, tant que le rendez-vous gouvernance n’a pas eu lieu.

### Avec qui

Cyril (supervision humaine principale).

Cette règle évite : dérive autonome, auto-modification incontrôlée, changement silencieux des règles du système.

## Cas particulier : sous-tâche triviale

Si une sous-tâche est suffisamment petite (1 fichier, ≤ 5 lignes), le PROJECT_SUPERVISOR peut fusionner les rôles TEST_WORKER + STEP_GLOBAL_REVIEWER en une seule étape, mais jamais coder lui-même.

---

# Points de rendez-vous humains

## Définition

Un point de rendez-vous humain est un arrêt obligatoire de l'orchestration autonome, imposant un retour du superviseur humain (Cyril) avant toute poursuite.

## Liste des points de rendez-vous T140.5

| # | Position | Raison | Statut |
|---|----------|--------|--------|
| 0 | **Modification gouvernance** | **Après toute modification de MASTER_PLAN.md, MISSION ORCHESTRATION.md, règles d'autonomie/orchestration/doctrine** | **⚠️ ACTIF** |
| 1 | Après T140.5A | Fin phase API/channels | ✅ Complété |
| 2 | Après T140.5B | Fin phase services. Stabilisation gouvernance avant controllers. | ✅ Complété |
| 3 | Après T140.5C | Fin phase referrals/rewards | ✅ Complété |
| 4 | Après T140.5D | Fin phase controllers métier. Avant admin/Auth/Livewire | ✅ Complété |
| 5 | **Intégration Review Cluster T140.5A-D** | **Conflit inter-agents, doctrine Guard Before Query, faux positifs, confidence levels, priorités réelles** | **⚠️ ACTIF** |

## Effets

Quand un point de rendez-vous est actif :

* le PROJECT_SUPERVISOR s'arrête après le merge de la sous-tâche en cours ;
* il produit un rapport d'étape ;
* il attend une décision humaine explicite (GO / NO-GO / périmètre ajusté) ;
* il n'ouvre aucune sous-tâche suivante sans cette validation.

## Lever un point de rendez-vous

Un point de rendez-vous est levé quand le superviseur humain :
* confirme la poursuite (GO) ;
* ajuste le périmètre si nécessaire ;
* ou décide un changement de direction.

---

# État final — T140.5 (après Review Cluster)

```text
T140.5A — Channels + ResolveApiOrganization         ✅ MERGED
T140.5B — LoopService + LoopMessageService           ✅ MERGED
T140.5C — ReferralService + RewardDispatcher         ✅ MERGED
T140.5D — LoopController                             ✅ MERGED
T140.5E — Admin/Livewire/Views cleanup               🔒 PAUSE (attente décision)
```

---

# Review Cluster Integration — Multi-Agent Arbitration & Findings Doctrine

## Origine

L'audit T140.5A-D a révélé un conflit inter-agents entre REVIEW_ARCHITECT (escalade, quality C) et TENANT_SAFETY_REVIEWER (pas d'escalade, quality B) sur les LoopMember queries sans tenant scope SQL.

L'audit ciblé LOOPMEMBER_TENANT_SCOPE_AUDIT a tranché : TENANT_SAFETY_REVIEWER correct. Tous les LoopMember queries sont des faux positifs.

Cette section fige les enseignements et les règles qui en découlent.

## Workflow Arbitrage Inter-Agents

### Déclencheur

Lorsque deux ou plusieurs agents de review émettent des verdicts contradictoires sur le même finding :

| Agent A | Agent B | Conflit |
|---------|---------|---------|
| Escalade (quality C) | Pas d'escalade (quality B) | **Actif** |
| Critical | Medium/Low | **Actif** |
| GO | NO-GO | **Actif** |

### Procédure

1. **Identifier le périmètre précis du conflit** — fichier, ligne, pattern
2. **Déclencher un audit ciblé** (mode READ-ONLY strict)
3. **L'audit ciblé examine** :
   - Le code exact (pas un grep, pas un résumé)
   - La protection existante en amont de chaque location
   - L'exploitabilité réelle (pas théorique)
   - Le contexte complet (pas un extrait)
4. **L'audit rend un verdict** : faux positif / vrai risque / dette technique
5. **Le conflit est résolu** par l'arbitrage final

### Règle de préséance

En cas de conflit persistant :

1. **L'agent avec le plus de contexte sur la couche concernée a préséance**
   - Sécurité tenant → TENANT_SAFETY_REVIEWER
   - Architecture code → REVIEW_ARCHITECT
   - Conventions Laravel → LARAVEL_REVIEWER
2. **Si les deux agents ont une autorité égale**, l'audit ciblé est décisif
3. **L'escalade n'est pas un jugement final** — elle déclenche un processus, pas une décision

## Procédure Audit Ciblé

### Définition

Un audit ciblé est une analyse READ-ONLY d'un ou plusieurs findings disputés, visant à déterminer la classification réelle du risque.

### Conditions de déclenchement

- Conflit inter-agents sur un finding
- Classification CRITICAL avec désaccord
- Finding basé sur grep pattern sans lecture contexte
- Demande explicite du REVIEW_SUPERVISOR ou du superviseur humain

### Règles

1. **Lecture seule stricte** — interdiction de modifier un fichier pendant l'audit
2. **Code exact** — l'audit lit le fichier complet, pas un extrait de grep
3. **Protection amont** — pour chaque location, vérifier si une validation existe AVANT
4. **Exploitabilité réelle** — démontrer le vecteur d'attaque, pas supposer
5. **Classification explicite** — chaque location reçoit une classe : faux positif / vrai risque / dette technique

### Livrable

Un rapport d'audit ciblé listant :
- Chaque location analysée
- La protection existante (avec numéros de ligne)
- L'exploitabilité réelle
- La classification
- La recommandation

## Confidence Levels des Findings

### Principes

Tous les findings ne se valent pas. Un grep match sans lecture contexte a un niveau de confiance inférieur à une revue manuelle avec lecture complète du code.

### Échelle

| Niveau | Critère | Action attendue |
|--------|---------|-----------------|
| **LOW** | grep match seul, sans lecture contexte | Lecture contexte obligatoire avant toute action |
| **MEDIUM** | lecture contexte partielle, protection amont partielle | Vérification manuelle, escalade possible |
| **HIGH** | lecture contexte complète, absence protection confirmée | Action corrective obligatoire |
| **CRITICAL** | vulnérabilité démontrable (ex: cross-org leak reproductible) | Blocage immédiat |

### Règles associées

- Un finding LOW ne peut pas justifier un NO-GO ou une escalade seul
- Un finding CRITICAL nécessite toujours démonstration reproductible
- Le niveau de confiance est ajusté après audit ciblé

## Doctrine Faux Positifs Tenant Safety

### Définition

Un faux positif tenant safety est un finding qui identifie une absence de scoping SQL direct alors qu'une protection équivalente (ou supérieure) existe ailleurs dans le flux.

### Cas typiques

| Pattern | Protections alternatives valides |
|---------|----------------------------------|
| `LoopMember::where('loop_id', $loop->id)` sans `where('organization_id')` | Guard amont `$loop->organization_id !== $orgId` avant la query |
| `Referral::where('referrer_user_id', $user->id)` sans `where('organization_id')` | Relation déduite du user (auto-scoped) |
| `Loop::find($id)` sans where clause | Guard immédiat après le find |

### Règle fondamentale

**L'absence de tenant scope SQL n'est pas une faille automatique si une protection équivalente existe ailleurs dans le flux.**

Le pattern valide est :

```text
1. Charger objet root (Loop, Referral, etc.)
2. Valider organization_id de l'objet root (guard amont)
3. Exécuter query dépendante
```

Ce pattern est appelé **"Guard Before Query"** et est considéré comme une protection tenant suffisante.

### Distinction faux positif vs vrai risque

| Critère | Faux positif | Vrai risque |
|---------|-------------|-------------|
| Protection amont | Oui (guard explicite avant query) | Non |
| Exploitabilité | Aucune (même avec ID enumeration) | Démonstrable |
| Impact | N/A — code comportemental correct | Data leak cross-org |

## Règle "Lecture Contexte Avant Escalade"

### Principe

Toute escalade (NO-GO, block, quality C/D) doit être précédée d'une lecture complète du fichier concerné.

### Interdictions

- **Interdit** : escalader sur un grep match sans lire le fichier
- **Interdit** : escalader sur un pattern général sans vérifier les exceptions
- **Interdit** : classer CRITICAL sans démonstration d'exploitabilité

### Processus obligatoire

1. Grep → trouver les locations candidates
2. Lecture fichier complet → comprendre le contexte
3. Vérifier protection amont pour chaque location
4. Évaluer l'exploitabilité réelle
5. Si aucune protection et exploitabilité démontrée → escalade
6. Si protection existe ou exploitabilité non démontrée → faux positif ou dette

## Guard Before Query — Clarification

### Le pattern validé

```php
// ÉTAPE 1 : valider l'objet root (guard amont)
if ($loop->organization_id !== $orgId) {
    abort(404);  // ou throw, return false, etc.
}

// ÉTAPE 2 : query dépendante (safe)
$member = LoopMember::where('loop_id', $loop->id)
    ->where('user_id', $user->id)
    ->first();
```

### Pourquoi c'est suffisant

- Le guard amont garantit que l'objet root (Loop) appartient à la même organization que l'utilisateur
- La query LoopMember ne peut donc matcher que des enregistrements du même organization
- Même avec ID enumeration, l'attaquant ne peut pas contourner le guard amont

### Defense-in-depth (optionnel)

Le pattern alternatif avec scoping SQL direct offre une redondance mais n'est pas obligatoire :

```php
// Pattern alternatif (redondant)
$member = LoopMember::where('loop_id', $loop->id)
    ->where('user_id', $user->id)
    ->whereHas('loop', fn($q) => $q->where('organization_id', $orgId))
    ->first();
```

### Quand le guard amont est INSUFFISANT

Le guard amont n'est pas suffisant quand :
- L'objet root est chargé sans aucune validation (ex: `Loop::find($id)` sans garde)
- L'objet root n'a pas de organization_id (ex: modèles sans tenant)
- La query dépendante peut remonter à un objet root d'une autre organization via une relation non-scopée
