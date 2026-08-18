# TASK-1239 — Product Spec : contrat du scenario pack (démonstration / dogfooding)

> **Statut** : SPEC PROPOSÉE. Plan validé par MASTER 2 avant rédaction ; validation
> finale du contenu via la revue de cette PR. Document **normatif visé**, aucun
> code, aucune migration, aucune donnée réelle livrés par cette tâche.
> **Rédigé** le 2026-08-18, sur `develop` à `b15724e` (contient TASK-1238).
> **Portée** : ce document est la référence pour T1240 (moteur de chargement,
> Organization-scoped), T1241 (UI Admin de gestion des scénarios), T1242
> (premier pack réel, ArtSciLab/Roger V1) et T1243 (rejeu/reset/nettoyage). En
> cas de divergence avec le TASK file de TASK-1239, **ce document fait foi**.
> **Doctrine de référence** : roadmap MASTER IA/RAG (bloc A, T1239, et section 3
> « doctrine non négociable » — Organization = Tenant, Loop ≠ Tenant, aucun
> fallback silencieux, aucune action métier durable sans validation humaine) ;
> `docs/ai/ARCHITECTURE.md` pour le socle technique existant (ContextBuilder,
> RAG, corrélation).
> **Ce document ne construit rien.** Aucune implémentation du moteur de
> chargement, de l'UI ou du contenu réel du pack ArtSciLab/Roger n'est livrée
> par cette tâche.

---

## 1. Objet et cadrage

Ce document définit le **contrat d'un scenario pack** : la structure que doit
respecter tout jeu de données de démonstration/dogfooding rechargeable dans
BouclePro.

Un scenario pack est une **description déclarative** d'un ensemble cohérent
de données (Organization, personas, Boucles, contenu documentaire,
interactions, configuration IA non secrète) permettant de faire la
démonstration du parcours IA/RAG canonique de façon reproductible, sans
dépendre d'un état ad hoc du repo ou d'une manipulation manuelle.

Ce contrat sert de référence pour les TASKs suivantes du bloc A :

- **T1240** — moteur de chargement scenario pack, Organization-scoped ;
- **T1241** — UI Admin de gestion des scénarios (sélection, prévisualisation,
  chargement, état, reset/suppression) ;
- **T1242** — premier pack réel, destiné au dogfooding Roger, construit à
  partir du runbook et des cas ArtSciLab ;
- **T1243** — rejeu / reset / nettoyage garantissant l'absence de dérive.

**Ce document ne construit aucune de ces briques.** Il ne contient :

- aucun code applicatif ;
- aucune migration ni schéma de table ;
- aucune donnée réelle, cliente, personnelle ou de production ;
- aucune implémentation du moteur de chargement (T1240) ni de l'UI (T1241).

Il hérite intégralement de la doctrine non négociable de la roadmap MASTER,
en particulier :

- **Organization = Tenant** ; un scenario pack ne définit jamais un périmètre
  qui déborde d'une seule Organization ;
- **Loop ≠ Tenant** ; les Boucles d'un pack restent rattachées à
  l'Organization du pack, jamais transverses ;
- aucun fallback silencieux vers une clé/credential plateforme ;
- aucune action métier durable sans validation humaine ;
- pas de deuxième système de configuration IA parallèle — le pack référence
  la Doctrine/les Prompts existants, il ne les redéfinit pas.

Les exemples illustratifs de ce document (noms de persona, domaine
`*-demo.test`, intitulés de Dossiers) sont **fictifs**, choisis pour la
lisibilité du contrat. Ils ne préjugent pas du contenu réel du pack
ArtSciLab/Roger, qui sera défini par T1242.

---

## 2. Identité du pack (nom / version)

Un scenario pack déclare une identité stable et non ambiguë :

| Champ | Description |
|---|---|
| `pack_id` | identifiant stable, slug unique (ex. `artscilab-roger-demo`) |
| `pack_name` | nom lisible (ex. « ArtSciLab — démonstration Roger ») |
| `pack_version` | version sémantique **propre au pack**, indépendante de la `VERSION` de l'application |
| `created_at` / `last_revised_at` | traçabilité de la définition, pas du chargement |
| `owner` | qui a défini/validé le contenu du pack (rôle produit, pas un compte technique) |
| `purpose` | une phrase : ce que ce pack démontre |

Règles :

- deux packs ne partagent jamais le même `pack_id` ;
- un pack déjà chargé une fois n'est **jamais modifié silencieusement** :
  toute évolution de son contenu déclaratif entraîne un incrément de
  `pack_version` ;
- le couple `(pack_id, pack_version)` doit permettre de savoir exactement ce
  qui a été chargé, à tout moment, sans reconstruction approximative.

---

## 3. Organization cible

Un scenario pack cible **exactement une Organization**, jamais plusieurs,
jamais « toutes », jamais une portée implicite.

- l'Organization cible est déclarée par référence explicite (slug ou
  identifiant stable), jamais devinée depuis le contexte d'exécution ;
- l'Organization cible doit être explicitement qualifiée comme Organization
  de démonstration/dogfooding ; un scenario pack ne doit jamais pouvoir
  cibler une Organization cliente réelle en production — ce garde-fou est un
  **prérequis de conception** pour T1240, pas une option ;
- un pack ne référence, ne lit et ne dépend **jamais** d'une autre
  Organization que sa cible (voir section 14).

---

## 4. Personas

Le pack définit une liste fermée de personas **fictifs**, réutilisables
d'un chargement à l'autre. Chaque persona déclare :

| Champ | Description |
|---|---|
| `persona_id` | identifiant stable interne au pack |
| `role` | membre / Admin Organization / SuperAdmin (aligné sur les personas minimum de T1244) |
| `demo_email` | adresse dans un espace de noms de démonstration dédié (ex. `xxx@artscilab-demo.test`), jamais une adresse réelle |
| `expected_rights` | droits attendus pour ce rôle dans l'Organization cible |

Les personas d'un pack sont des **comptes de démonstration reproductibles**,
pas des utilisateurs de production, et ne doivent porter aucune donnée
personnelle réelle (nom complet réel, email réel, numéro, avatar réel).

---

## 5. Boucles

Le pack définit la liste des Boucles (Loops) qu'il crée ou réutilise :

| Champ | Description |
|---|---|
| `loop_id` | identifiant stable interne au pack |
| `loop_name` | nom lisible de la Boucle |
| `purpose` | ce que la Boucle démontre |
| `organization` | doit être l'Organization cible du pack — aucune exception |
| `participants` | sous-ensemble des `persona_id` définis en section 4 |

Rappel d'invariant : **Loop ≠ Tenant**. La Boucle est un espace de
collaboration à l'intérieur de l'Organization, jamais une frontière de
tenant alternative.

---

## 6. Dossiers / articles / fichiers

Le pack décrit le contenu documentaire (source du RAG) qu'il crée dans
l'Organization cible :

| Champ | Description |
|---|---|
| `folder_id` | identifiant stable interne au pack |
| `title` | titre du Dossier/article |
| `parent_folder` | rattachement hiérarchique éventuel |
| `content_type` | type de contenu (article, fichier texte/markdown, etc.) — limité aux formats déjà ingérables par le pipeline RAG au moment du chargement réel |
| `freshness_intent` | statut de fraîcheur voulu à des fins de démonstration (ex. « à jour », « volontairement périmé » si le scénario a besoin de le montrer) |

Contraintes strictes :

- tout contenu doit être **fictif ou explicitement libre de droits /
  anonymisé** ;
- aucune donnée personnelle réelle, aucun document interne réel, aucun
  extrait de correspondance réelle ;
- le pack ne doit jamais s'appuyer sur un import de données réelles
  existantes comme raccourci de constitution de contenu.

---

## 7. Interactions / messages

Le pack décrit les échanges simulés nécessaires pour démontrer le parcours
nerveux V1 (Ask the Folders, ChatLoop, « Demander à l'IA » canonique) :

| Champ | Description |
|---|---|
| `interaction_id` | identifiant stable interne au pack |
| `persona` | émetteur, parmi les `persona_id` définis |
| `context` | Boucle ou Dossier concerné |
| `message_content` | contenu du message simulé (fictif) |
| `expected_outcome` | résultat attendu, y compris le passage par la validation humaine si l'interaction déclenche une action métier durable |

Aucune interaction du pack ne doit produire par elle-même une action métier
durable sans le passage humain prévu par la doctrine.

---

## 8. Configuration IA non secrète

Le pack référence la configuration IA applicable, sans jamais en dupliquer
le contenu ni y inclure de secret :

- **Doctrine Organization** appliquée — référence versionnée si disponible
  (T1258), sinon état par défaut de l'Organization cible ;
- **Prompts administrables** actifs concernés — référence au prompt, pas de
  copie de son contenu dans le pack ;
- **Provider/capability** activés pour la démonstration — référencés par nom
  logique uniquement ; la résolution du credential réel reste hors du
  périmètre du pack et relève exclusivement de la configuration Organization
  déjà en place (aucun credential, clé, secret ou token dans le pack, sous
  aucune forme) ;
- **budget/quota démonstratif** si le scénario a besoin d'illustrer un état
  économique particulier, défini par référence à la garde économique
  existante, jamais par un mécanisme économique parallèle.

---

## 9. Préconditions

Un chargement de pack exige un état vérifiable avant toute opération :

- l'Organization cible existe et est explicitement qualifiée comme
  Organization de démonstration/dogfooding (section 3) ;
- aucune donnée résiduelle d'un chargement précédent non nettoyé n'est
  présente pour ce `pack_id` (sauf rechargement volontaire idempotent,
  section 10) ;
- l'environnement cible est autorisé pour ce type d'opération (jamais un
  environnement portant des données clients réelles) ;
- la Doctrine/les Prompts référencés par le pack (section 8) existent et
  sont dans un état compatible avec le pack au moment du chargement.

---

## 10. Idempotence

Rejouer le chargement du **même pack** (même `pack_id` + `pack_version`) sur
la **même Organization cible** ne doit jamais :

- dupliquer les entités déjà créées par un chargement précédent du même
  pack ;
- corrompre ou mélanger les entités de deux chargements différents ;
- faire dériver les compteurs économiques (crédits/consommation) au-delà de
  ce que le rechargement lui-même justifie.

Le pack doit déclarer sa **clé d'idempotence** : l'ensemble des identifiants
stables (internes au pack, section 2/4/5/6) qui permettent à un moteur de
chargement de reconnaître « ceci a déjà été créé par ce pack » plutôt que de
recréer une entité équivalente sous une nouvelle identité.

Ce document pose l'exigence contractuelle ; le mécanisme technique
d'idempotence est hors scope et relève de T1240.

---

## 11. Reset

Le pack définit ce que signifie « reset » pour lui-même :

- retour à l'état obtenu **immédiatement après un chargement propre** du
  pack (pas une suppression totale — voir section 12) ;
- un état de reset doit être **vérifiable** : les preuves définies en
  section 13 doivent pouvoir être rejouées après reset et donner un résultat
  identique à un premier chargement propre.

---

## 12. Suppression

Le pack définit ce que signifie « suppression » pour lui-même :

- retrait complet et **borné** de toutes les entités que ce pack a créées
  (personas de démonstration, Boucles, Dossiers/articles/fichiers,
  interactions) dans l'Organization cible ;
- **aucun impact** sur des données préexistantes de l'Organization qui ne
  proviennent pas du pack ;
- interdiction stricte de toute suppression globale non bornée à l'échelle
  de l'Organization ou de la plateforme — cette interdiction est déjà posée
  pour l'UI Admin (T1241) et s'applique symétriquement au contrat du pack
  lui-même.

---

## 13. Preuves / critères de validation

Un chargement (ou un reset) réussi doit permettre de vérifier, sans
ambiguïté :

- présence de toutes les entités attendues par le pack (personas, Boucles,
  Dossiers/articles/fichiers, interactions), conformes à leur déclaration ;
- absence de toute entité orpheline ou dupliquée issue d'un rechargement ;
- absence de toute fuite cross-tenant (section 14) ;
- cohérence des compteurs économiques avec ce que le pack déclare
  consommer, s'il consomme quoi que ce soit ;
- une checklist reproductible, lisible par Cyril, permettant de confirmer
  visuellement l'état — cette checklist alimente directement la recette par
  persona (T1244) et le jalon « Roger Ready » (T1245), sans s'y substituer.

---

## 14. Interdiction stricte cross-tenant

Invariant central, non négociable, hérité de la doctrine BouclePro :

- toute entité créée par un scenario pack reste **strictement contenue**
  dans l'Organization cible déclarée en section 3 ;
- un pack ne référence, ne lit, n'écrit et ne dépend **jamais** d'une
  Organization autre que sa cible, y compris indirectement (pas de persona
  partagé entre Organizations, pas de Dossier visible depuis une autre
  Organization, pas de credential ou de configuration empruntée à une autre
  Organization) ;
- toute preuve de chargement (section 13) doit inclure une vérification
  explicite et positive d'absence de fuite cross-tenant, pas une simple
  absence d'erreur.

Ce point est un critère de recevabilité du contrat lui-même : un scenario
pack qui ne peut pas démontrer son confinement à une seule Organization
n'est pas conforme à ce contrat, quelle que soit la qualité de son contenu
démonstratif par ailleurs.

---

## 15. Hors scope explicite

Ce document ne définit et ne livre :

- aucun schéma de données ni migration (relève de T1240 si nécessaire) ;
- aucun moteur de chargement, ni mécanisme technique d'idempotence/reset/
  suppression (T1240) ;
- aucune UI Admin de gestion des scénarios (T1241) ;
- aucun contenu réel du pack ArtSciLab/Roger — les identifiants et exemples
  utilisés ici sont illustratifs du contrat uniquement (T1242 définira le
  contenu réel) ;
- aucune donnée réelle, cliente, personnelle n'est chargée, créée ou
  référencée par ce document.

**Aucun code ne doit être écrit à partir de ce contrat avant validation
explicite de cette spec.**
