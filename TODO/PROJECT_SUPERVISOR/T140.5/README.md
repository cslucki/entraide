# T140.5 — README
# TODO/PROJECT_SUPERVISOR/T140.5/README.md

Ne code pas T140.5 globalement.

Lance une orchestration multi-agents autonome.

Architecture agents :

- PRIMARY_1_PROJECT_SUPERVISOR
  chef de projet autonome, maintient le plan vivant, découpe les sous-tâches, coordonne les agents.

- PRIMARY_2_REVIEW_SUPERVISOR
  assistante supervision/review, vérifie cohérence, périmètre, qualité, risques et conformité.

- TECH_WRITER
  seul agent autorisé à modifier le code.

- TEST_WORKERS
  agents read-only/test-only pour validations, audits et sécurité tenant.

Règles absolues :

- une seule branche par sous-tâche ;
- jamais de branche globale T140.5 ;
- un seul writer ;
- aucun worker ne modifie le code ;
- aucun worker ne commit ;
- aucun worker ne lance d'autre worker ;
- le PROJECT_SUPERVISOR est le seul agent autorisé à coordonner d'autres agents ;
- le REVIEW_SUPERVISOR ne lance pas d'agents ;
- aucun agent ne mélange plusieurs sous-tâches ;
- aucun patch global T140.5.

Commencer uniquement par :

T140.5A — Channels + ResolveApiOrganization

LOCK :

T140.5B
T140.5C
T140.5D
T140.5E

sont LOCKED.

Aucun agent ne doit démarrer ces sous-tâches tant que :
- T140.5A n'est pas vert ;
- T140.5A n'est pas documenté ;
- T140.5A n'est pas prêt merge ;
- T140.5A n'est pas validé humainement.

Plan vivant obligatoire :

Créer et maintenir :

TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md

Créer aussi :

TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/

Le master plan doit contenir :

- état global ;
- sous-tâches ;
- ordre d'exécution ;
- branches ;
- périmètres autorisés ;
- interdits ;
- décisions ;
- blockers ;
- résultats tests ;
- statut merge ;
- next actions ;
- historique.

Le PROJECT_SUPERVISOR peut modifier uniquement :

- TODO/PROJECT_SUPERVISOR/T140.5/MASTER_PLAN.md
- TODO/PROJECT_SUPERVISOR/T140.5/BRANCHES_PREVUES.md
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/PROJECT_SUPERVISOR/*
- TODO/TASK-144-t140-5-runtime-organization-id.md
- TODO/TASK-144-t140-5A-channels-resolve-api-organization.md

Il ne modifie jamais :

- app/
- routes/
- tests/
- docs/

Le TECH_WRITER est le seul autorisé à modifier :

- code ;
- tests ;
- docs d'audit ;
- TASK T140.5A ;
- TODO/PROJECT_SUPERVISOR/T140.5/AGENTS/TECH_WRITER/*

Le REVIEW_SUPERVISOR et les TEST_WORKERS sont read-only sauf leurs dossiers rapports.

État final attendu :

- T140.5A uniquement implémenté ;
- tests T140.5A exécutés ;
- rapports workers produits ;
- review globale produite ;
- master plan mis à jour ;
- aucun changement hors périmètre ;
- aucun commit/push sans validation humaine.

Instructions détaillées :

- TODO/PROJECT_SUPERVISOR/T140.5/MISSION ORCHESTRATION.md
- TODO/PROJECT_SUPERVISOR/T140.5/README.md
