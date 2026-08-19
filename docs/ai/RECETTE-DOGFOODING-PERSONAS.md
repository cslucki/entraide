# Recette dogfooding complète par persona — check-list Cyril

TASK-1244. Périmètre lu dans
`TODO/SPECS/ROADMAP-MASTER-IA-RAG-T1235-T1300-2026-08-18.md`, lignes 395-399 :
personas minimum membre / Admin Organization / SuperAdmin, produit = check-list
lisible par Cyril, pas un test technique de plus.

Ce document n'est **pas** une preuve technique exhaustive (voir plutôt
`docs/ai/RUNBOOK-ROGER-V2.md` pour la référence code, ou les recettes DoD
`_local/captures/TASK-1232` / `TASK-1234` pour ce niveau de détail). C'est le
parcours que Cyril peut rejouer lui-même en quelques minutes, avec ce qui a
été vérifié réellement (pas supposé) le 2026-08-19, et ce qui reste à
regarder.

## Comment rejouer

- Banc `bouclepro_ai_validation`, port 8010, worktree `ia-p1-observabilite`
  (tmux `task1204-ai-server` / `task1204-ai-worker`).
- Organization de démonstration : `artscilab-demo` (pack `artscilab-roger-demo`,
  TASK-1240 à TASK-1243). Chargé et testé sur cette Organization uniquement.
- Si `scenario-pack:status` échoue avec une table `scenario_pack_loads`
  manquante : `APP_ENV=ai-validation php artisan migrate --force` (les deux
  migrations TASK-1240 n'étaient pas encore appliquées sur ce banc au
  moment de cette recette).
- Charger/rejouer le pack : `APP_ENV=ai-validation php artisan scenario-pack:load artscilab-roger-demo artscilab-demo`
  puis `scenario-pack:reset artscilab-roger-demo artscilab-demo --yes`.
- Comptes (mot de passe `password` sur ce banc) :

| Persona | Identifiant | Connexion |
|---|---|---|
| Membre | `theo@artscilab-demo.test` | http://127.0.0.1:8010/org/artscilab-demo/login |
| Admin Organization | `maya@artscilab-demo.test` | http://127.0.0.1:8010/org/artscilab-demo/login |
| SuperAdmin plateforme | `admin@ai-validation-org-a.ai-validation.test` | http://127.0.0.1:8010/login |

Captures de cette session : `_local/captures/TASK-1244/` (local, non versionné).

---

## Persona 1 — Membre (Theo)

| # | Parcours vérifié | Résultat | Preuve |
|---|---|---|---|
| 1 | Connexion, accès à « My loops », entrée dans un Loop (European Projects) | OUI | capture dashboard membre |
| 2 | « Ask the Folders » (pill dans le fil, TASK-1213) — question hors-sources | **Refus correct**, aucune fabrication : « Je n'ai pas trouvé cette information dans les sources auxquelles j'ai accès… » | `membre-ask-the-folders-citation.jpg` (2e capture de la série) |
| 3 | « Ask the Folders » — question dans le périmètre indexé | Réponse sourcée, citation `[S1]` avec titre, Loop, type de document, extrait, lien « Open », mention « Doctrine ArtSciLab appliquée » | `membre-ask-the-folders-citation.jpg` |
| 4 | FAB canonique « Open BouclePro AI » (bouton bas droite, TASK-1237) | **NON — voir Observations** | repro ci-dessous |
| 5 | Contenu du Loop cohérent avec le pack (roadmap, décisions, help request Maya) | OUI, à une exception près — voir Observations (donnée orpheline) | — |

## Persona 2 — Admin Organization (Maya)

| # | Parcours vérifié | Résultat | Preuve |
|---|---|---|---|
| 1 | Connexion → redirection automatique vers `/org/artscilab-demo/admin` | OUI (11 membres, 5 Loops, 10 services, 11 requests) | `admin-org-dashboard.jpg` |
| 2 | Réglages IA de l'Organization (`/org/artscilab-demo/admin/ai`) | OUI — statut « Ready », provider/modèle visibles, **clé API jamais réaffichée** (write-only), dépense connue du mois affichée (0,0174 $ / 5,00 $ de budget) | `admin-org-ai-settings.jpg` |
| 3 | Aucun secret ni donnée réelle exposée sur cette page | OUI | `admin-org-ai-settings.jpg` |

## Persona 3 — SuperAdmin plateforme

| # | Parcours vérifié | Résultat | Preuve |
|---|---|---|---|
| 1 | Connexion (`admin@ai-validation-org-a…`) → `/admin/dashboard` plateforme (vue transverse, pas bornée à une seule Organization) | OUI (17 utilisateurs, stats plateforme) | `superadmin-scenario-pack-admin.jpg` (dashboard précédent la capture) |
| 2 | UI Admin scénarios (`/admin/scenario-packs`, TASK-1241) — état du pack `artscilab-roger-demo` sur `artscilab-demo` | OUI — version chargée 1.0.0, date de chargement, 386 entités | `superadmin-scenario-pack-admin.jpg` |
| 3 | Rejeu du pack (2 cycles `scenario-pack:reset`, CLI — bouton UI équivalent nécessite une confirmation navigateur non automatisable ici) | OUI — 386 entités stables, **0 orphelin** aux deux cycles (cf. TASK-1243) | sortie CLI, voir Progress Log TASK-1244 |
| 4 | Supervision sans lecture de contenu privé (page scénarios = compteurs agrégés uniquement, aucun message ni contenu membre) | OUI | `superadmin-scenario-pack-admin.jpg` |

---

## Observations (non corrigées ici, hors périmètre NO_APP_CI de TASK-1244)

### 1. FAB « Open BouclePro AI » — panneau ne s'ouvre pas au premier clic

- **Page** : n'importe quel Loop où le membre a accès (constaté sur
  `/org/artscilab-demo/loops/{European Projects}`), persona membre.
- **Action** : clic sur le bouton flottant bas-droite « Open BouclePro AI »
  (`data-ai-fab-toggle`, entrée canonique exposée par TASK-1237).
- **Attendu** : le panneau `#ai-fab-panel` (choix « Ask the AI » / « Ask the
  Folders » / etc.) s'affiche.
- **Observé** : l'état réactif Alpine `open` passe bien à `true`
  (`aria-expanded="true"`, vérifié via `Alpine.$data()`), mais le panneau
  reste `display:none`, rect 0×0 — invisible. Reproduit deux fois
  indépendamment sur page rechargée à froid (clic réel via référence DOM et
  clic JS programmatique). Aucune erreur JS applicative en console.
- **Point important** : le chemin alternatif (pill « Ask the Folders »
  directement dans le fil du Loop, scope Alpine différent) **fonctionne**
  correctement de bout en bout (voir Persona 1, items 2-3). Le défaut
  semble isolé au FAB lui-même, pas à la fonctionnalité RAG sous-jacente.
- **Non corrigé** : bug produit réel découvert pendant la recette, noté et
  escaladé séparément, hors périmètre TASK-1244 (documentaire).

### 2. Donnée orpheline « TEST TASK-1211 » dans le Loop European Projects

- Une carte « Help request » intitulée « TEST TASK-1211 — financement
  européen » (auteure Maya) est présente dans le Loop European Projects,
  sans lien avec le contenu du pack `artscilab-roger-demo` (le pack ne la
  déclare pas ; elle a survécu à deux cycles `scenario-pack:reset`, ce qui
  est cohérent puisqu'elle n'est pas trackée par le registrar du moteur).
- Vraisemblablement une donnée de test manuelle antérieure au moteur de
  scenario pack (TASK-1240), laissée dans la base `bouclepro_ai_validation`.
- **Distinct du point remover T1240** (DossierFile SoftDeletes non purgé
  par `remove()`, déjà escaladé séparément sur TASK-1243) : ce point-ci n'a
  pas été recroisé pendant cette recette — aucune suppression de pack n'a
  été exécutée (seulement `load` et `reset`).
- **Non corrigé** : noté pour information ; un nettoyage éventuel de cette
  ligne est une décision produit (Cyril), pas une action de TASK-1244.

---

## Verdict

Les trois personas minimum (membre, Admin Organization, SuperAdmin) ont un
parcours dogfooding fonctionnel sur `artscilab-demo`, avec une preuve
positive et une preuve de refus correct pour le cœur RAG (« Ask the
Folders », citations sourcées, doctrine appliquée), une supervision
SuperAdmin sans lecture de contenu privé, et un cycle rejeu/reset
idempotent (386 entités, 0 orphelin, 2 cycles). Un défaut produit réel est
identifié sur le point d'entrée canonique du FAB (à traiter séparément) ;
il n'empêche pas l'usage du parcours RAG par un chemin alternatif déjà en
place dans l'UI.
