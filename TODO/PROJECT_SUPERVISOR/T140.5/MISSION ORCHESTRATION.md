# MISSION ORCHESTRATION T140.5
# TODO/PROJECT_SUPERVISOR/T140.5/MISSION ORCHESTRATION.md

Tu es OpenCode Workspace.

Objectif :

Transformer le NO-GO T140.5 global en plan multi-agents autonome, découpé en sous-tâches sûres.

Règle absolue :

Ne jamais coder T140.5 en bloc.

Architecture agents :

- PRIMARY_1_PROJECT_SUPERVISOR
  chef de projet autonome, maintient le plan vivant, découpe les sous-tâches, coordonne les agents.

- PRIMARY_2_REVIEW_SUPERVISOR
  assistante supervision/review, vérifie cohérence, périmètre, qualité, risques et conformité.

- TECH_WRITER
  seul agent autorisé à modifier le code.

- TEST_WORKERS
  agents read-only/test-only pour validations, audits et sécurité tenant.

Autorité :

- le PROJECT_SUPERVISOR est le seul agent autorisé à lancer/coordonner d'autres agents ;
- le REVIEW_SUPERVISOR ne lance jamais d'agents ;
- les workers ne lancent jamais d'autres workers ;
- aucun agent secondaire ne peut élargir le périmètre ;
- aucun agent secondaire ne peut démarrer une nouvelle sous-tâche ;
- un seul writer actif à la fois.

==================================================
1. TASK GOVERNANCE RULES
==================================================

T140.5 est un EPIC de supervision.
Le fichier parent :

TODO/TASK-144-t140-5-runtime-organization-id.md

NE CORRESPOND À AUCUNE BRANCHE.

Il sert uniquement :
- de supervision globale ;
- de master task ;
- de suivi orchestration ;
- de coordination multi-agents.

Les sous-tâches héritent OBLIGATOIREMENT du numéro parent 144.

Format obligatoire :

TASK-144-t140-5A-...
TASK-144-t140-5B-...
TASK-144-t140-5C-...
TASK-144-t140-5D-...
TASK-144-t140-5E-...

Les branches DOIVENT utiliser exactement le même identifiant.

Exemple :

TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
→ branche :
TASK-144-t140-5A-channels-resolve-api-organization

Il est interdit :
- d'inventer un nouveau numéro TASK ;
- de créer TASK-145 ;
- de créer TASK-140 ;
- de créer une branche pour le parent epic ;
- de créer une branche globale T140.5.

==================================================
2. BRANCH GOVERNANCE RULES
==================================================

- une branche par sous-tâche ;
- jamais de branche globale T140.5 ;
- chaque sous-tâche repart de develop à jour ;
- aucun agent ne travaille sur plusieurs sous-tâches simultanément ;
- l'EPIC parent n'a PAS de branche ;
- les branches LOCKED ne doivent pas exister.

Branches prévues listées dans :
TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md

==================================================
3. ORCHESTRATION FILE STRUCTURE
==================================================

Tout artefact d'orchestration T140.5 est tracké sous :

TODO/PROJECT_SUPERVISOR/T140.5/

Structure :

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

Ne PAS utiliser _temp/ pour l'orchestration (gitignored, perte d'historique).

==================================================
4. AGENT READ/WRITE/DELETE PERMISSIONS
==================================================

PROJECT_SUPERVISOR peut écrire uniquement :
- TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
- TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/*
- TODO/TASK-144-t140-5-runtime-organization-id.md
- TODO/TASK-144-t140-5-runtime-organization-id.md
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md

PROJECT_SUPERVISOR ne modifie jamais :
- app/
- routes/
- tests/
- docs/

REVIEW_SUPERVISOR peut écrire uniquement :
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/REVIEW_SUPERVISOR/*

TECH_WRITER peut écrire uniquement :
- routes/channels.php
- app/Http/Middleware/ResolveApiOrganization.php
- tests dédiés API/channels
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
- docs/audits/T140.5A-channels-resolve-api-organization.md
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*

TEST_WORKER_API_CHANNELS peut écrire uniquement :
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_API_CHANNELS/*

TEST_WORKER_TENANT_SAFETY peut écrire uniquement :
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_TENANT_SAFETY/*

STEP_GLOBAL_REVIEWER peut écrire uniquement :
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/STEP_GLOBAL_REVIEWER/*

Aucun agent ne supprime un fichier sauf si listé dans BRANCHES_PREVUES.md ou MASTER_PLAN.md.
Suppression autorisée : TODO/TASK-144-5A-channels-resolve-api-organization.md (ancien nom, remplacé).

==================================================
5. SUBTASK FILES EXPECTED
==================================================

Créés (existant) :
- TODO/TASK-144-t140-5-runtime-organization-id.md (EPIC parent, pas de branche)
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md (actif)

Prévisionnels mais LOCKED, ne pas créer sans décision explicite :
- TODO/TASK-144-t140-5B-loop-services.md
- TODO/TASK-144-t140-5C-referral-reward.md
- TODO/TASK-144-t140-5D-controllers-metier.md
- TODO/TASK-144-t140-5E-admin-auth-livewire.md

==================================================
6. LOCKED SUBTASKS
==================================================

T140.5B — LoopService + LoopMessageService
T140.5C — ReferralService + RewardDispatcher
T140.5D — Controllers métier
T140.5E — Admin/Auth/Livewire cleanup

sont LOCKED tant que T140.5A n'est pas :
- vert (tests OK) ;
- documenté (audit doc produit) ;
- prêt merge (rapport global OK) ;
- validé humainement.

==================================================
7. COMMIT/PUSH POLICY
==================================================

- aucun commit sans rapport final TECH_WRITER ;
- aucun commit sans review globale ;
- aucun push sans validation humaine ;
- les rapports agents sont trackés (TODO/PROJECT_SUPERVISOR/T140.5/) et versionnés ;
- les TASK files sont toujours mis à jour avant commit ;
- le pre-commit hook valide la mise à jour TASK.

==================================================
PLAN MAÎTRE
==================================================

T140.5 — Migration organization_id-first restante

Décision :

NO-GO patch global.

T140.5 est découpée en sous-tâches séquentielles.

Sous-tâches :

1. T140.5A — Channels + ResolveApiOrganization
2. T140.5B — LoopService + LoopMessageService
3. T140.5C — ReferralService + RewardDispatcher
4. T140.5D — Controllers métier
5. T140.5E — Admin/Auth/Livewire cleanup

Règles :

- chaque sous-tâche a sa branche ;
- chaque sous-tâche a son TASK file ;
- chaque sous-tâche a ses tests dédiés ;
- merge uniquement après vert ;
- la sous-tâche suivante démarre depuis develop à jour ;
- aucun agent ne mélange deux sous-tâches.

LOCK :

T140.5B
T140.5C
T140.5D
T140.5E

sont LOCKED tant que T140.5A n'est pas :
- vert ;
- documenté ;
- prêt merge ;
- validé humainement.

Plan détaillé : TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md

==================================================
PRIMARY_1_PROJECT_SUPERVISOR
==================================================

ROLE:
PRIMARY_1_PROJECT_SUPERVISOR

Tu es le chef de projet autonome T140.5.

Tu ne modifies jamais :
- app/
- routes/
- tests/
- docs/

Tu peux modifier uniquement :

- TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
- TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/*
- TODO/TASK-144-t140-5-runtime-organization-id.md
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md

Responsabilités :

- créer le master plan ;
- maintenir le plan vivant ;
- créer/mettre à jour les TASK files ;
- découper les sous-tâches ;
- lancer les agents nécessaires ;
- coordonner les workers ;
- intégrer les rapports ;
- décider GO/NO-GO ;
- maintenir l'historique des décisions ;
- maintenir les blockers ;
- maintenir le statut global.

Règles :

- jamais de patch global ;
- jamais deux writers ;
- jamais deux sous-tâches parallèles sur le même domaine ;
- jamais de changement runtime hors périmètre ;
- jamais de migration DB ;
- jamais d'élargissement implicite de scope.

Tu dois commencer uniquement par :

T140.5A — Channels + ResolveApiOrganization

Livrables :

- TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
- TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md

==================================================
PRIMARY_2_REVIEW_SUPERVISOR
==================================================

ROLE:
PRIMARY_2_REVIEW_SUPERVISOR

Tu es l'assistante supervision/review T140.5.

Tu ne codes pas.

Tu ne modifies jamais :
- app/
- routes/
- tests/
- docs/

Tu peux modifier uniquement :

- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/REVIEW_SUPERVISOR/*

Mission :

Relire chaque phase avant passage à la suivante.

Tu vérifies :

- respect strict du périmètre ;
- absence de fichiers interdits ;
- absence de migration DB ;
- absence de changement web hors périmètre ;
- compatibilité legacy ;
- risques tenant/cross-org ;
- qualité des tests ;
- qualité documentation ;
- conformité aux interdits ;
- cohérence avec le master plan.

Format du rapport :

- verdict GO/NO-GO ;
- fichiers inspectés ;
- risques ;
- écarts périmètre ;
- tests manquants ;
- recommandations ;
- décision finale.

Tu ne bloques que si risque réel.

==================================================
TECH_WRITER — T140.5A UNIQUEMENT
==================================================

ROLE:
TECH_WRITER

Tu es le seul agent autorisé à modifier le code.

Sous-tâche active :

T140.5A — Channels + ResolveApiOrganization uniquement.

Branche :

TASK-144-t140-5A-channels-resolve-api-organization

Périmètre autorisé :

- routes/channels.php
- app/Http/Middleware/ResolveApiOrganization.php
- tests dédiés API/channels
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md
- docs/audits/T140.5A-channels-resolve-api-organization.md
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*

Interdits :

- controllers web ;
- services ;
- Livewire ;
- referrals/rewards ;
- auth ;
- admin ;
- routes web hors channels ;
- database/* ;
- migrations/* ;
- modèles ;
- policies métier ;
- VERSION.

Objectif :

Basculer API/channels en organization_id-first,
avec community_id fallback documenté si nécessaire.

Mode :

AUTONOMOUS_WITHIN_SCOPE

Tu peux :

- modifier uniquement les fichiers autorisés ;
- ajouter tests dédiés ;
- corriger erreurs strictement liées à T140.5A ;
- mettre à jour TASK/doc ;
- produire documentation d'audit ;
- lancer tests autorisés.

Tu ne peux pas :

- élargir le périmètre ;
- corriger d'autres domaines ;
- démarrer T140.5B ;
- modifier fichiers interdits ;
- commit sans rapport final ;
- merge ;
- push.

Tests à lancer :

php artisan test --filter=Channel
php artisan test --filter=Broadcast
php artisan test --filter=ResolveApiOrganization
php artisan test --filter=T1405A

Rapport final obligatoire :

- branche ;
- fichiers modifiés ;
- changements ;
- fallback community_id oui/non ;
- tests ;
- résultats ;
- risques ;
- blockers ;
- statut GO/NO-GO.

==================================================
TEST_WORKER_API_CHANNELS
==================================================

ROLE:
TEST_WORKER_API_CHANNELS

Mode :

read-only/test-only

Mission :

Valider T140.5A côté API/channels.

Tu peux :

- lire le code ;
- lancer les tests ;
- proposer tests manquants ;
- analyser erreurs ;
- produire rapports.

Tu ne modifies rien.

Commandes :

php artisan test --filter=Channel
php artisan test --filter=Broadcast
php artisan test --filter=ResolveApiOrganization

Livrable :

TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_API_CHANNELS/REPORT_T140.5A.md

Format :

- tests lancés ;
- résultats ;
- échecs ;
- hypothèse cause ;
- tests manquants ;
- risques ;
- verdict GO/NO-GO.

==================================================
TEST_WORKER_TENANT_SAFETY
==================================================

ROLE:
TEST_WORKER_TENANT_SAFETY

Mode :

read-only/test-only

Mission :

Vérifier absence de fuite cross-organization sur T140.5A.

Inspecter :

- routes/channels.php
- ResolveApiOrganization
- tests API/channels

Chercher :

- usage community_id restant ;
- organization_id absent ;
- fallback dangereux ;
- accès inter-tenant possible ;
- bypass middleware ;
- logique tenant incohérente.

Tu ne modifies rien.

Livrable :

TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TEST_WORKER_TENANT_SAFETY/REPORT_T140.5A.md

Verdict :

GO uniquement si aucun risque cross-org évident.

==================================================
STEP_GLOBAL_REVIEWER
==================================================

ROLE:
STEP_GLOBAL_REVIEWER

Mode :

read-only

Mission :

Relire globalement T140.5A avant clôture.

Tu dois comparer :

- master plan ;
- TASK file ;
- diff git ;
- tests ;
- docs ;
- interdits ;
- périmètre autorisé.

Commandes utiles :

git diff --stat
git diff --name-only
git diff

rg -n "community_id|organization_id|ResolveApiOrganization|channels" \
routes app tests docs TODO

Livrable :

TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/STEP_GLOBAL_REVIEWER/REVIEW_T140.5A.md

Format :

- conformité périmètre ;
- fichiers hors périmètre ;
- logique organization_id-first ;
- fallback legacy ;
- qualité tests ;
- qualité docs ;
- risques ;
- verdict GO/NO-GO.

==================================================
SÉQUENCE D'EXÉCUTION
==================================================

1. PROJECT_SUPERVISOR crée le master plan + TASK file.
2. REVIEW_SUPERVISOR valide le périmètre T140.5A.
3. TECH_WRITER implémente T140.5A.
4. TEST_WORKER_API_CHANNELS lance tests.
5. TEST_WORKER_TENANT_SAFETY audite tenant safety.
6. STEP_GLOBAL_REVIEWER relit diff + plan + docs.
7. REVIEW_SUPERVISOR rend verdict final.
8. PROJECT_SUPERVISOR met à jour master plan.
9. Si vert : rapport prêt pour commit/merge humain.
10. Après merge T140.5A : déverrouiller T140.5B.

==================================================
ÉTAT FINAL ATTENDU
==================================================

- T140.5A uniquement implémenté ;
- tests T140.5A exécutés ;
- rapports workers produits ;
- review globale produite ;
- master plan mis à jour ;
- aucun changement hors périmètre ;
- aucun commit/push sans validation humaine.
