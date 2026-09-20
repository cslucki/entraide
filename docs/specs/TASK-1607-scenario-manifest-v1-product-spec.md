# Scenario Manifest V1 — Product Spec

> **TASK** : TASK-1607
> **Statut** : SPEC NORMATIVE PROPOSÉE — documentation only
> **Version du langage** : `1.0`
> **Date** : 2026-09-20
> **Baseline inspectée** : `develop` à `dcd422b73309b3bc1e24c172450453337ec8b3fe`, VERSION `1.606`

Cette spécification définit le langage JSON du futur **BouclePro Scenario
Manifest V1**. Elle ne livre aucun parseur, JSON Schema, loader, écran,
migration, banque d'avatars ni conversion d'un pack existant.

Les mentions suivantes indiquent la nature d'une affirmation :

- **FACT** : comportement ou modèle constaté dans le produit au moment de la
  rédaction ;
- **DECISION** : contrat normatif de Manifest V1 ;
- **OPEN QUESTION** : arbitrage produit réellement non tranché. La présente
  version n'en laisse aucun qui bloque un Validator V1.

La formule produit est :

> Une IA décrit un monde BouclePro. BouclePro vérifie ce monde. L'humain le
> valide. Le moteur le construit.

---

## 1. Problem

**FACT.** Les Scenario Packs actuels sont des classes PHP connues de
l'application. Ils savent construire des démonstrations riches et sûres, mais
une IA externe qui ne connaît ni Laravel ni les tables BouclePro ne peut pas
produire un nouveau pack sans écrire du code.

Le produit a besoin d'un format intermédiaire qui permette à ChatGPT, Claude,
OpenCode ou une autre IA de décrire une communauté fictive, puis à BouclePro de
la vérifier et de la montrer avant toute écriture métier. L'import d'un texte
ne doit jamais être confondu avec son exécution.

Sans contrat fermé, chaque producteur inventerait ses champs, ses relations et
ses raccourcis. Un Validator devrait alors deviner l'intention, et un loader
risquerait de transformer des identifiants ou chemins fournis par l'appelant en
accès à un tenant, à une classe ou au stockage.

## 2. Product goal

**DECISION.** Manifest V1 est un langage JSON déclaratif, strict et versionné
qui décrit des objets métier BouclePro fictifs. Il doit permettre :

1. la création, le collage ou l'import dans le SuperAdmin ;
2. le parsing et la validation complète sans écriture métier ;
3. une prévisualisation à plat, lisible par une personne ;
4. une validation humaine explicite du contenu exact ;
5. le chargement ultérieur dans une **nouvelle Organization sandbox** par un
   adaptateur du moteur Scenario Packs existant ;
6. l'export du même document JSON, sans identifiant DB ni secret.

Une IA qui connaît cette seule spécification doit pouvoir produire un manifeste
valide. Un développeur qui connaît cette seule spécification doit pouvoir
écrire un Validator donnant les mêmes résultats à entrée identique.

## 3. Non-goals

Cette TASK et Manifest V1 ne définissent pas :

- un langage de programmation, des hooks, du templating ou du PHP embarqué ;
- un format qui reflète les tables, modèles ou classes Laravel ;
- un second moteur de Scenario Packs ;
- le provisionnement d'une Organization cliente existante ;
- l'exécution d'appels IA, l'ingestion RAG ou la fabrication de résultats IA ;
- la conversion des quatre packs PHP existants ;
- une banque d'avatars ;
- un système générique d'import de fichiers distants ;
- une promesse de parité entre toutes les capacités futures de BouclePro et le
  langage V1.

## 4. Invariants

### 4.1 Produit et tenant

**DECISION.** Les invariants suivants sont absolus :

- **Organization = Tenant** ;
- **Loop != Tenant** ; une Boucle appartient à l'unique sandbox du manifeste ;
- `Community`, `community_id` et `current_community` sont absents du langage ;
- le manifeste décrit **une nouvelle sandbox**, jamais une cible existante ;
- l'Organization réelle, son UUID et son slug final sont choisis par
  BouclePro ;
- aucune référence ne sort du document ;
- tous les utilisateurs sont fictifs et appartiennent à la sandbox créée ;
- aucune donnée, photo, adresse ou credential de production n'est admis ;
- les décisions durables restent attribuées à des humains fictifs explicites.

### 4.2 Donnée, jamais code

Le document est un objet JSON UTF-8. Il ne contient et ne déclenche :

- aucune classe PHP, nom de modèle, table, méthode, callback ou commande ;
- aucun script, expression évaluable, template exécutable ou interpolation ;
- aucun chemin de fichier arbitraire ;
- aucune URL à télécharger ;
- aucun UUID de base de données ;
- aucun champ libre transmis tel quel à un ORM.

Le Validator applique une allowlist de champs. Tout champ inconnu est une
erreur. Le loader futur transforme les objets métier validés en appels aux
primitives métier canoniques ; il ne fait jamais de mass assignment du JSON.

### 4.3 Architecture cible

**FACT.** Le produit possède actuellement `ScenarioPackDefinition`,
`ScenarioPackLoader`, `ScenarioPackEntityRegistrar`, `ScenarioPackResetter`,
`ScenarioPackRemover` et `ScenarioPackOrganizationGuard`.

**DECISION.** L'intégration future doit suivre cette forme conceptuelle sans
être implémentée dans cette TASK :

```text
ScenarioPackDefinition
        ^
        |
ManifestScenarioPack
        |
ScenarioManifest
```

`ScenarioManifest` représente le document validé. `ManifestScenarioPack`
adapte ce document à `ScenarioPackDefinition`. Le loader, le registrar, le
resetter, le remover et le garde Organization restent les autorités de cycle
de vie. Aucun gros refactor n'est requis.

## 5. Manifest lifecycle

### 5.1 États

```text
DRAFT --Validate + confirmation humaine--> VALID --Load--> LOADED
  ^                                         |
  |---------------- Update -----------------|
```

| État | Contrat |
|---|---|
| `DRAFT` | JSON stocké côté administration, éditable et prévisualisable. Il peut être invalide. Aucune donnée métier n'est créée. |
| `VALID` | Le contenu exact a passé toutes les validations et un SuperAdmin a confirmé humainement le digest affiché. Toute modification le rend immédiatement `DRAFT`. |
| `LOADED` | Le digest validé a été chargé dans la sandbox créée par BouclePro. L'identité réelle de la sandbox et le registre d'entités restent des données système, hors manifeste. |

Le digest est le SHA-256 du JSON canonique selon **RFC 8785 / JCS**, encodé en
UTF-8 après validation NFC des chaînes. L'ordre des tableaux reste significatif
pour le digest, même quand un champ `order` porte l'ordre métier. Le digest est
affiché avant confirmation et enregistré hors du token.

### 5.2 Opérations

| Opération | Précondition | Effet autorisé |
|---|---|---|
| Create | SuperAdmin | Crée un brouillon vide conforme à la structure V1. |
| Read / Preview | tout état | Lit uniquement le manifeste et les résultats de validation. |
| Update | `DRAFT` ou `VALID` | Remplace le document ; l'état devient `DRAFT`, le digest et l'approbation précédents sont invalidés. |
| Duplicate | tout état | Copie le contenu vers un nouvel `id`, une nouvelle `organization.proposed_slug`, une version `1.0.0` et l'état `DRAFT`. Aucun état de load n'est copié. |
| Delete | `DRAFT` ou `VALID` | Supprime la définition administrative ; aucune donnée métier n'existe à supprimer. |
| Delete | `LOADED` | Action destructive confirmée : retrait borné via le moteur existant, puis suppression de la sandbox seulement si le registre prouve qu'elle appartient au pack ; le manifeste n'est supprimé qu'après succès complet. |
| Import JSON | SuperAdmin | Parse et stocke un `DRAFT`. **Import != Load**. |
| Export / Copy token | tout état | Rend uniquement le JSON du manifeste, sans état, UUID, digest, secret ou métadonnée de chargement. |
| Validate | `DRAFT` | Exécute les contrôles sans écriture métier, montre toutes les erreurs, puis demande une confirmation humaine explicite du digest avant `VALID`. |
| Load | `VALID` | Crée une nouvelle sandbox et applique exactement le digest validé. |
| Reset | `LOADED` | Restaure la sandbox à l'état produit par ce même digest, via `ScenarioPackResetter`; ce n'est ni un import ni une suppression. |

Un double clic ou rejeu réseau sur `Load` avec le même manifeste/digest doit
retourner le chargement existant, sans créer une seconde sandbox.

## 6. Schema V1

### 6.1 Enveloppe JSON fermée

Toutes les propriétés ci-dessous sont obligatoires. Les collections sans
contenu valent `[]`. `training` reste présent même sans formation afin qu'un
consommateur n'ait jamais à deviner la variante du document.

```json
{
  "schema_version": "1.0",
  "id": "example-pack",
  "version": "1.0.0",
  "name": "Example pack",
  "description": "Description factuelle du monde fictif.",
  "purpose": "Ce que la démonstration permet de vérifier.",
  "locale": "fr",
  "assets": { "avatar_bank": "fictional-avatars-v1" },
  "organization": {},
  "users": [],
  "loops": [],
  "memberships": [],
  "dossiers": [],
  "articles": [],
  "files": [],
  "messages": [],
  "categories": [],
  "skills": [],
  "service_requests": [],
  "services": [],
  "polls": [],
  "events": [],
  "decisions": [],
  "roadmap_items": [],
  "training": {
    "modules": [],
    "sequences": [],
    "progress": [],
    "assignments": [],
    "submissions": []
  }
}
```

Propriétés de l'enveloppe :

| Champ | Type et règle |
|---|---|
| `schema_version` | chaîne, valeur exacte `1.0` |
| `id` | stable key globale du pack |
| `version` | SemVer `MAJOR.MINOR.PATCH`, sans préversion ni build en V1 |
| `name` | chaîne non vide, 1–120 caractères |
| `description` | chaîne non vide, 1–2 000 caractères |
| `purpose` | chaîne non vide, 1–500 caractères |
| `locale` | `fr` ou `en` |
| `assets.avatar_bank` | valeur exacte `fictional-avatars-v1` en V1 |

### 6.2 Types scalaires communs

| Nom | Contrat V1 |
|---|---|
| stable key | chaîne de 1 à 64 caractères, regex `^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$` |
| proposed slug | même regex qu'une stable key, 3 à 64 caractères |
| email fictif | adresse unique, domaine obligatoire `.test`, 254 caractères max |
| texte court | chaîne normalisée NFC, sans caractère de contrôle, longueur propre au champ |
| entier | nombre JSON entier ; une chaîne numérique est invalide |
| booléen | `true` ou `false` ; `0`, `1` et chaînes sont invalides |
| enum | correspondance sensible à la casse sur les valeurs listées |
| offset | entier signé relatif à `load_started_at`, jamais une date absolue |

`null` n'est accepté que pour les champs explicitement marqués nullable.
L'absence d'un champ obligatoire est une erreur. Un champ optionnel peut être
omis ; s'il est présent, il doit respecter son type.

### 6.3 Temps relatif

Le token ne fige pas une démonstration sur une date civile périmée.

- `offset_minutes` est compris entre `-525600` et `525600` ;
- `day_offset` est compris entre `-365` et `365` ;
- lors du Load, BouclePro capture un unique `load_started_at` UTC ;
- tous les instants sont calculés à partir de cette ancre puis stockés par les
  primitives canoniques ;
- `order` départage explicitement les messages, modules, séquences et éléments
  de roadmap ; l'ordre du tableau JSON n'est jamais une règle métier implicite.

## 7. Object catalog

Les tableaux de cette section sont normatifs. `R` signifie required, `O`
optional et `N` nullable.

### 7.1 Organization sandbox

```json
{
  "name": "AMT — Formation IA",
  "proposed_slug": "amt-formation-ia",
  "description": "Sandbox fictive de formation.",
  "locale": "fr"
}
```

| Champ | Règle |
|---|---|
| `name` R | 1–120 caractères |
| `proposed_slug` R | proposed slug ; suggestion seulement |
| `description` R | 1–2 000 caractères |
| `locale` R | doit être identique à la locale racine |

Aucun autre paramètre Organization n'est exposé en V1. BouclePro crée la
sandbox avec un profil sûr et non client : inactive pour la distribution
publique tant que le produit ne l'a pas ouverte, sans credential IA, avec les
Boucles et profils IA activés seulement selon les besoins du manifeste. Le
slug final peut être dérivé pour éviter une collision ; une collision ne
transforme jamais la proposition en sélection d'une Organization existante.

### 7.2 Users / personas

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `users` |
| `first_name` R | 1–80 caractères |
| `name` R | nom de famille ou nom d'usage, 1–120 caractères |
| `email` R | email fictif `.test`, unique dans le manifeste |
| `bio` R,N | `null` ou 0–2 000 caractères |
| `available` R | booléen |
| `location` R,N | `null` ou 1–160 caractères |
| `avatar` R,N | `null` ou clé de la banque locale |
| `organization_role` R | `admin` ou `member`; jamais `superadmin` |
| `member_ai_profile` R,N | `null` ou objet ci-dessous |

Une clé d'avatar respecte `^(female|male|neutral)-[0-9]{2}$`. Sa présence dans
la banque déclarée est vérifiée sans réseau. Le nom, l'email, la bio et le
lieu doivent être fictifs ; le suffixe `.test` est une garde mécanique, pas
une autorisation d'importer l'identité d'une personne réelle.

Mot de passe, vérification d'email, token de session et credentials sont absents
du langage. Le provisionnement crée des credentials non exportables selon la
politique sandbox du produit ; aucun mot de passe partagé ne vient du JSON.

`member_ai_profile` :

| Champ | Règle |
|---|---|
| `status` R | `draft` ou `published` |
| `summary` R,N | `null` ou 0–2 000 caractères |
| `service_scope` R,N | `null` ou 0–2 000 caractères |
| `experience_context` R,N | `null` ou 0–2 000 caractères |
| `target_audience` R | 0–20 chaînes de 1–160 caractères |
| `problems_helped` R | 0–20 chaînes de 1–160 caractères |
| `skills` R | 0–30 chaînes de 1–120 caractères |
| `help_types` R | 0–20 chaînes de 1–120 caractères |
| `boundaries` R | 0–20 chaînes de 1–200 caractères |
| `preferred_contact_action` R,N | `null`, `message` ou `service_request` |
| `tone` R,N | `null`, `warm`, `direct` ou `pedagogical` |

Un profil `published` exige au moins une valeur dans `skills` ou
`problems_helped` et un `summary` non vide. Le manifeste ne contient aucune
sortie générée, aucun prompt et aucune trace IA.

### 7.3 Loops and memberships

`loops[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `loops` |
| `name` R | 1–120 caractères |
| `description` R | 1–2 000 caractères |
| `type` R | `general`, `project`, `coaching` ou `training` |
| `owner` R | référence `users.key` |
| `visibility` R | `private` ou `public` |
| `access_mode` R | `open`, `request` ou `invitation` |
| `root_dossier` R | référence `dossiers.key` |

Les types `writing`, `networking`, `peer_support`, `ai_agent` et tout type
custom sont invalides en V1, même s'ils existent dans une autre surface du
produit.

`memberships[]` :

| Champ | Règle |
|---|---|
| `loop` R | référence `loops.key` |
| `user` R | référence `users.key` |
| `role` R | `owner`, `facilitator` ou `member` |

Le couple `(loop,user)` est unique. Chaque Loop a exactement un membership
`owner`, et il doit correspondre à `loops[].owner`. Tout auteur d'un objet de
Boucle est membre actif de cette Boucle. `moderator` est un alias historique
interne et n'est jamais écrit dans un manifeste.

### 7.4 Dossiers, root documents, articles and text files

`dossiers[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `dossiers` |
| `name` R | 1–160 caractères |
| `owner` R | référence `users.key` |
| `loop` R,N | `null` ou référence `loops.key` |
| `parent` R,N | `null` ou référence à un autre Dossier |
| `visibility` R | `private`, `organization` ou `loop` |
| `root_document` R,N | `null` ou objet ci-dessous |

Un Dossier référencé par `loop.root_dossier` a `loop` égal à cette Loop,
`parent: null`, `visibility: "loop"` et un `root_document` non null. Aucun
autre Dossier ne peut revendiquer la même Loop comme racine. Un enfant hérite
du périmètre effectif de son parent ; son graphe est acyclique et sa profondeur
maximale est 10 en Manifest V1.

`root_document` :

| Champ | Règle |
|---|---|
| `title` R | 1–200 caractères |
| `author` R | référence `users.key`, membre de la Loop |
| `format` R | `markdown` ou `html` |
| `content` R | 1–100 000 caractères, contenu sanitizable |

`articles[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `articles` |
| `dossier` R | référence `dossiers.key` |
| `author` R | référence `users.key`, autorisé sur le Dossier |
| `title` R | 1–200 caractères |
| `summary` R,N | `null` ou 0–500 caractères |
| `status` R | `draft` ou `published` |
| `audience` R | `organization` ou `loop` ; `loop` exige un Dossier gouverné par une Loop |
| `format` R | `markdown` ou `html` |
| `content` R | 1–100 000 caractères, contenu sanitizable |
| `published_offset_minutes` R,N | obligatoire et `<= 0` si `published`, sinon `null` |

`files[]` décrit uniquement de faux fichiers textuels inline :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `files` |
| `dossier` R | référence `dossiers.key` |
| `uploaded_by` R | référence `users.key`, autorisé sur le Dossier |
| `name` R | basename de 1–160 caractères, extension `.md` ou `.html`, aucun `/`, `\\` ou `..` |
| `media_type` R | `text/markdown` ou `text/html`, cohérent avec l'extension |
| `content` R | 1–100 000 caractères, contenu sanitizable |

Le loader dérive lui-même disque, chemin, taille et SHA-256. Ces propriétés ne
sont jamais déclarées. Il ne télécharge rien et ne lit aucun chemin fourni.

### 7.5 ChatLoop

Le nom public canonique est la collection `messages`, dont chaque entrée porte
`"type": "loop_message"`. Le vieux `entity_type: "interaction"` de certains
Seeders n'appartient pas au langage Manifest.

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `messages` |
| `type` R | valeur exacte `loop_message` |
| `loop` R | référence `loops.key` |
| `author` R | référence `users.key`, membre de la Loop |
| `body` R | 1–10 000 caractères |
| `format` R | `plain` ou `markdown` |
| `order` R | entier 0–9999, unique dans la Loop |
| `offset_minutes` R | offset ; l'ordre temporel doit suivre `order` |
| `reply_to` R,N | `null` ou référence à un message antérieur de la même Loop |

V1 ne crée que des messages humains. Les types système, réponses d'agent,
projections techniques et métadonnées sont produits par les primitives du
produit quand un objet métier les exige.

### 7.6 Entraide: categories, skills, requests and offers

`categories[]` est facultatif au sens métier mais son tableau est toujours
présent :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `categories` |
| `name` R | 1–120 caractères |
| `color` R | couleur hexadécimale `^#[0-9A-Fa-f]{6}$` |

`skills[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `skills` |
| `category` R | référence `categories.key` |
| `name` R | 1–120 caractères |

`service_requests[]` représente une demande d'aide :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `service_requests` |
| `author` R | référence `users.key` |
| `title` R | 1–255 caractères |
| `description` R | 1–5 000 caractères |
| `category` R | référence `categories.key` |
| `delivery_mode` R | `remote`, `onsite` ou `both` |
| `budget_min` R | entier 0–1 000 000 |
| `budget_max` R,N | `null` ou entier >= `budget_min`, max 1 000 000 |
| `deadline_day_offset` R,N | `null` ou -365..365 |
| `status` R | `open`, `in_progress` ou `closed` |
| `highlight_in_loop` R,N | `null` ou référence à une Loop dont l'auteur est membre |

`services[]` représente une proposition/offre d'aide :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `services` |
| `author` R | référence `users.key` |
| `title` R | 1–255 caractères |
| `description` R | 1–5 000 caractères |
| `category` R | référence `categories.key` |
| `skills` R | 0–20 références `skills.key` |
| `delivery_mode` R | `remote`, `onsite` ou `both` |
| `points_cost` R | entier 0–1 000 000 |
| `status` R | `active` ou `paused` |
| `highlight_in_loop` R,N | `null` ou référence à une Loop dont l'auteur est membre |

`highlight_in_loop` demande au loader d'utiliser le mécanisme produit de mise
en avant d'une Offre/Demande dans une Boucle. Il ne copie pas l'objet et ne
crée pas un lien cross-tenant. Il ne demande pas une projection ChatLoop ; une
projection éventuellement produite par un workflow produit futur serait une
donnée dérivée et n'apparaîtrait pas dans `messages`.

### 7.7 Collaboration: polls, events, decisions and roadmap

`polls[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `polls` |
| `loop` R | référence `loops.key` |
| `author` R | membre de la Loop |
| `question` R | 1–500 caractères |
| `description` R,N | `null` ou 0–2 000 caractères |
| `selection_type` R | `single` ou `multiple` |
| `status` R | `open` ou `closed` |
| `options` R | 2–10 objets `{key,label}` ; keys uniques dans le poll, labels non vides et distincts après trim/casse |
| `votes` R | 0–100 objets `{user,options}` ; un user une fois, membre de la Loop, 1 option en single, 1–10 distinctes en multiple |

Un poll `closed` est clos par l'auteur lors du chargement après création des
votes. Un poll `open` n'a pas de métadonnée de clôture.

`events[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `events` |
| `loop` R | référence `loops.key` |
| `author` R | membre de la Loop |
| `title` R | 1–255 caractères |
| `description` R,N | `null` ou 0–5 000 caractères |
| `format` R | `in_person`, `online` ou `hybrid` |
| `starts_offset_minutes` R | offset |
| `duration_minutes` R | entier 15–1440 |
| `timezone` R | identifiant IANA valide, max 64 caractères |
| `location` R,N | requis pour `in_person`/`hybrid`, sinon `null` |
| `meeting_url` R,N | URL HTTPS max 2 048, requise pour `online`/`hybrid`, jamais visitée par le loader |
| `visibility` R | `loop` ou `organization` |
| `status` R | `scheduled` ou `cancelled` |
| `responses` R | 0–100 objets `{user,response}` avec `going`, `maybe` ou `not_going`; user membre, unique |

`visibility: "organization"` exige une Loop `visibility: "public"` et un
auteur `owner`; cette règle V1 est volontairement plus étroite que toute
permission dynamique possible. Les réponses sont appliquées avant une
éventuelle annulation.

`decisions[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `decisions` |
| `loop` R | référence `loops.key` |
| `author` R | membre de la Loop |
| `title` R | 1–255 caractères |
| `rationale` R,N | `null` ou 0–5 000 caractères |
| `decided_day_offset` R | -365..0 |
| `message` R,N | `null` ou référence à un message de la même Loop |
| `supersedes` R,N | `null` ou décision antérieure de la même Loop ; une décision ne peut être remplacée qu'une fois |

`roadmap_items[]` :

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `roadmap_items` |
| `loop` R | référence `loops.key` |
| `created_by` R | membre de la Loop |
| `title` R | 1–255 caractères |
| `description` R,N | `null` ou 0–5 000 caractères |
| `status` R | `todo`, `in_progress` ou `done` |
| `position` R | entier 0–9999, unique dans `(loop,status)` |
| `assignees` R | 0–3 références distinctes à des membres de la Loop |
| `due_day_offset` R,N | `null` ou -365..365 |
| `decision` R,N | `null` ou décision de la même Loop |

Les messages d'annonce créés par les services de poll/event et les dates de
completion dérivées sont des données produit, non des champs de manifeste.

La présence d'un objet implique l'activation de sa Card canonique sur la Loop
si le preset du type ne l'a pas déjà activée : `core.polls`, `core.events`,
`core.decisions`, `core.roadmap` ou `core.marketplace`. Cette activation est
dérivée, prévisualisée et réalisée par la primitive de composition existante ;
le JSON ne déclare ni clé de Card ni configuration arbitraire. Les objets
TRAINING impliquent les Cards training livrées par le preset `training`.

## 8. References / stable keys

### 8.1 Règle générale

**DECISION.** Le token ne contient aucun UUID. Chaque objet adressable porte
une stable key lisible. Une référence est la valeur exacte de cette key, sans
préfixe, JSON Pointer ni nom de table.

Les keys sont uniques dans leur collection. Les options de poll sont uniques
dans leur poll. Les memberships et certains états individuels ont une clé
naturelle composée explicitée ci-dessous.

| Objet | Identité stable pour le registrar futur |
|---|---|
| user | `user:<key>` |
| loop | `loop:<key>` |
| membership | `membership:<loop>:<user>` |
| dossier | `dossier:<key>` |
| root document | `root_document:<dossier>` |
| article/file/message/etc. | `<type>:<key>` |
| poll option | `poll_option:<poll>:<option-key>` |
| poll vote | `poll_vote:<poll>:<user>` |
| event response | `event_response:<event>:<user>` |
| progress | `progress:<sequence>:<user>` |
| submission | `submission:<assignment>:<user>` |

Ces chaînes sont un contrat d'idempotence conceptuel. Elles ne sont pas des
identifiants DB et ne sont pas exposées comme `entity_type` public.

### 8.2 Graphe et ordre de résolution

Le Validator résout tout le graphe avant Load. Le loader futur peut ensuite
appliquer cet ordre topologique : Organization, users/profiles,
categories/skills, Loops/memberships/root Dossiers, autres Dossiers et
contenus, messages, entraide, collaboration, training structure, training
state. Toute référence manquante ou cyclique rend le manifeste entier
`INVALID`.

Une référence ne peut viser que l'objet attendu par son champ. La même chaîne
peut exister dans deux collections sans ambiguïté parce que le type attendu est
fixé par le schéma ; il est néanmoins recommandé aux producteurs de choisir
des keys descriptives.

## 9. Validation rules

### 9.1 Validation en phases, sans écriture métier

Le Validator exécute toutes les phases et retourne toutes les erreurs qu'il
peut établir sans dépendre d'une phase invalide :

1. enveloppe et encodage ;
2. schéma/version et champs autorisés ;
3. types, formats, enums et limites locales ;
4. unicité des keys et clés composées ;
5. résolution des références ;
6. invariants relationnels et tenant ;
7. contenu sanitizable et actifs locaux ;
8. calcul du digest et rapport de synthèse.

La validation ne crée ni Organization, ni user, ni registre, ni fichier, ni
interaction. Elle peut lire le catalogue local d'avatars et les registres de
types/services nécessaires au contrôle, sans appeler de provider.

### 9.2 Limites chiffrées

| Limite | Valeur V1 |
|---|---:|
| taille du JSON UTF-8 | 2 MiB |
| profondeur JSON | 20 |
| total d'objets déclarés, enfants inclus | 10 000 |
| users | 100 |
| loops | 20 |
| memberships | 500 |
| dossiers | 100 |
| articles | 200 |
| files | 200 |
| somme des contenus root/article/file/message | 1 MiB |
| messages | 1 000 |
| categories / skills | 50 / 200 |
| service_requests / services | 100 / 100 |
| polls / events / decisions | 100 chacun |
| roadmap_items | 200 |
| training modules / sequences | 100 / 500 |
| training progress / assignments / submissions | 5 000 / 200 / 5 000 |

Zéro objet est valide pour une collection sauf : au moins 1 user, 1 Loop, 1
membership et 1 Dossier sont requis pour un manifeste chargeable.

### 9.3 HTML, Markdown and URLs

La validation ne corrige pas silencieusement un contenu. Elle le passe dans le
même sanitizer déterministe que le futur rendu ; si le résultat diffère, elle
retourne `UNSAFE_CONTENT`.

Allowlist HTML V1 : `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `blockquote`,
`code`, `pre`, `h1` à `h4`, `a`. Seuls `href`, `title` et `rel` sont permis sur
`a`. `href` accepte `https://` et `mailto:` ; il est affiché comme lien et
n'est jamais téléchargé pendant Validate ou Load. Les scripts, styles,
handlers `on*`, iframes, images, audio, vidéo, objets, SVG, URLs `data:`,
`javascript:` et CSS sont rejetés.

Le Markdown autorise les équivalents de cette allowlist. Le HTML brut dans du
Markdown suit exactement les mêmes règles. Les images Markdown sont rejetées.

### 9.4 Error contract

Chaque erreur a exactement cette forme :

```json
{
  "code": "REFERENCE_NOT_FOUND",
  "path": "/messages/3/author",
  "message": "Unknown user key 'student-99'."
}
```

- `code` est stable, en `UPPER_SNAKE_CASE` ;
- `path` est un JSON Pointer RFC 6901 ; `/` vise la racine ;
- `message` est localisable et humainement lisible ; il ne contient ni stack
  trace, classe, SQL, chemin serveur ni secret ;
- les erreurs sont triées par `path`, puis `code`, puis `message` ;
- une même cause sur un même path n'est émise qu'une fois.

Codes minimaux V1 : `INVALID_JSON`, `INVALID_UTF8`, `PAYLOAD_TOO_LARGE`,
`MAX_DEPTH_EXCEEDED`, `UNKNOWN_SCHEMA_VERSION`, `MISSING_FIELD`,
`UNKNOWN_FIELD`, `INVALID_TYPE`, `INVALID_FORMAT`, `INVALID_ENUM`,
`VALUE_TOO_LONG`, `LIMIT_EXCEEDED`, `DUPLICATE_KEY`,
`DUPLICATE_COMPOSITE_KEY`, `REFERENCE_NOT_FOUND`, `REFERENCE_WRONG_SCOPE`,
`REFERENCE_CYCLE`, `OWNER_MEMBERSHIP_MISMATCH`, `TIMELINE_INCONSISTENT`,
`UNSAFE_CONTENT`, `AVATAR_NOT_FOUND`, `FORBIDDEN_PROPERTY`,
`FORBIDDEN_OBJECT` et `TENANT_TARGET_FORBIDDEN`.

Une validation réussie retourne `VALID` avec le digest, les compteurs et zéro
erreur. Toute erreur retourne `INVALID`; il n'existe pas d'avertissement qui
soit secrètement ignoré au Load.

## 10. Sandbox / tenant security

### 10.1 Propriétés interdites à toute profondeur

Le Validator rejette avec `TENANT_TARGET_FORBIDDEN` toute propriété dont le
nom, comparé en ASCII lowercase, est :

```text
organization_id
target_organization
target_organization_id
tenant
tenant_id
community
community_id
current_community
```

Il rejette avec `FORBIDDEN_PROPERTY` :

```text
id                 (sauf l'id racine normatif du manifeste)
uuid
model
class
class_name
table
connection
disk
path
command
callback
provider
api_key
secret
token              (le document complet peut être appelé token, mais aucun champ token n'existe)
```

Cette garde s'ajoute au rejet des champs inconnus. Elle fournit une erreur de
sécurité explicite plutôt qu'un simple message de schéma.

### 10.2 Création de la sandbox

Le Load ne reçoit jamais une Organization cible. Il reçoit uniquement le
manifest validé et son digest. BouclePro :

1. revalide le digest et l'état `VALID` ;
2. réserve une nouvelle Organization sandbox ;
3. choisit son UUID et son slug final ;
4. l'inscrit dans le mécanisme d'autorisation Scenario Pack requis ;
5. appelle l'adaptateur avec l'Organization objet, jamais avec un identifiant
   issu du JSON ;
6. enregistre chaque entité créée dans le registrar ;
7. annule l'ensemble si une primitive échoue.

Le slug proposé peut devenir `amt-formation-ia-2` ou une autre forme sûre. Il
ne provoque jamais l'adoption de `main`, d'un slug client ou d'une ligne
existante. Les slugs réservés `main`, `admin`, `api`, `app`, `www`, `prod`,
`production` et `develop` sont invalides comme proposition.

### 10.3 Primitive canonique obligatoire

**DECISION.** Le futur loader utilise les services métier canoniques lorsqu'ils
existent : création de Boucle et owner, root Dossier/document, memberships,
messages, sondages, événements, décisions, marketplace, support de cours,
progression, travaux et remises. Il respecte leurs événements, validations et
effets dérivés.

Les raccourcis historiques de Seeder ne sont pas le contrat. Un loader ne doit
pas normaliser Manifest V1 sur des `updateOrCreate` dispersés, sur le vieux mot
`interaction`, ni sur des colonnes DB. Si une primitive manque, la TASK
d'implémentation doit introduire un adaptateur métier borné et testé avant de
charger cet objet.

## 11. CORE V1

CORE V1 comprend obligatoirement le vocabulaire suivant, même si certaines
collections sont vides dans un pack donné :

- metadata, Organization sandbox et assets ;
- users/personas et `MemberAiProfile` ;
- Loops et memberships ;
- Dossiers, root documents, articles et fichiers textuels inline ;
- messages ChatLoop humains et replies ;
- categories, skills, demandes et offres d'aide ;
- polls, events, decisions et roadmap items.

Un pack CORE V1 sans formation conserve `training` avec cinq tableaux vides.
Un pack TRAINING V1 est également un pack CORE valide et utilise le même
`schema_version: "1.0"`.

## 12. TRAINING extension

Training V1 est une section isolée du même langage. Tous ses objets doivent
viser une Loop `type: "training"`. Les formateurs et stagiaires sont les mêmes
users et memberships que dans CORE ; il n'existe pas de seconde inscription.
Le parcours V1 est `sequential`, valeur canonique par défaut du produit. Un
mode `free` pourra être ajouté par une version ultérieure ; aucun champ
`path_mode` n'est accepté en `schema_version: "1.0"`.

### 12.1 Modules

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `training.modules` |
| `loop` R | Loop de type `training` |
| `title` R | 1–255 caractères |
| `summary` R,N | `null` ou 0–2 000 caractères |
| `position` R | entier 0–9999, unique et contigu à partir de 0 dans la Loop |
| `created_by` R | membre `owner` ou `facilitator` de la Loop |

### 12.2 Sequences

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `training.sequences` |
| `module` R | référence module |
| `title` R | 1–255 caractères |
| `position` R | entier contigu à partir de 0 dans le module |
| `requires_validation` R | booléen |
| `created_by` R | owner/facilitator de la Loop du module |
| `content` R | exactement une variante ci-dessous |

Variantes de `content` :

```json
{ "type": "text", "body": "Texte de la séquence." }
{ "type": "article", "article": "article-key" }
{ "type": "file", "file": "file-key" }
```

Une référence article/file doit viser un contenu de la même Organization et
d'un Dossier gouverné par la même Loop training.

### 12.3 CourseSequenceProgress

| Champ | Règle |
|---|---|
| `sequence` R | référence sequence |
| `user` R | membre de la Loop, unique par `(sequence,user)` |
| `status` R | `in_progress`, `submitted`, `completed`, `validated` ou `redo` |
| `started_offset_minutes` R,N | `null` ou offset <= 0 |
| `completed_offset_minutes` R,N | requis pour `completed`/`validated`, sinon nullable selon historique |
| `validated_by` R,N | requis pour `validated`, owner/facilitator ; sinon `null` |
| `validated_offset_minutes` R,N | requis pour `validated`, sinon `null` |
| `unlocked_by` R,N | owner/facilitator ou `null` |
| `unlocked_offset_minutes` R,N | présent si et seulement si `unlocked_by` l'est |

`available` et `unavailable` sont des conclusions calculées, jamais des états
stockés dans le manifeste. Les offsets d'une ligne doivent être croissants.

### 12.4 CourseAssignment

| Champ | Règle |
|---|---|
| `key` R | stable key unique dans `training.assignments` |
| `loop` R | Loop training |
| `sequence` R,N | `null` ou sequence de la même Loop |
| `title` R | 1–255 caractères |
| `brief` R,N | `null` ou 0–5 000 caractères |
| `due_offset_minutes` R,N | `null` ou offset |
| `position` R | entier contigu à partir de 0 dans la Loop |
| `created_by` R | owner/facilitator |

### 12.5 CourseSubmission

| Champ | Règle |
|---|---|
| `assignment` R | référence assignment |
| `user` R | membre de la Loop, unique par `(assignment,user)` |
| `body` R,N | `null` ou 0–20 000 caractères sanitizables |
| `file` R,N | `null` ou file du Dossier racine de la même Loop |
| `status` R | `draft`, `submitted`, `validated` ou `redo` |
| `submitted_offset_minutes` R,N | requis pour `submitted`/`validated`/`redo` |
| `feedback` R,N | `null` ou 0–5 000 caractères |
| `reviewed_by` R,N | requis pour `validated`/`redo`, owner/facilitator |
| `reviewed_offset_minutes` R,N | présent si et seulement si `reviewed_by` l'est |

Une submission `submitted`, `validated` ou `redo` doit avoir `body` ou `file`.
Une submission `draft` peut être vide. Les dates respectent l'ordre soumis puis
relu.

### 12.6 CourseQuiz

**DECISION.** `CourseQuiz` et ses questions, options, tentatives et scores sont
reportés à Manifest V1.1. Aucun champ `quizzes` n'existe en V1 ; l'ajouter à un
document `schema_version: "1.0"` produit `UNKNOWN_FIELD` ou
`FORBIDDEN_OBJECT`. Ce choix garde le contrat V1 fermé sans inventer le modèle
de tentative et de révélation des réponses.

## 13. Avatar strategy

**DECISION.** Un user déclare soit :

```json
"avatar": null
```

soit une clé logique :

```json
"avatar": "female-03"
```

La banque `fictional-avatars-v1` sera un asset local, versionné et auditable.
Elle ne contient aucune photo de production et ne requiert aucune URL. Le
Validator contrôle la syntaxe puis l'existence de la clé dans l'index local.
Le loader résout la clé ; le token ne connaît jamais le chemin physique.

`null` est parfaitement valide et déclenche le fallback initiales existant.
L'absence future de la banque ne rend pas l'import dangereux : un token qui
utilise une clé reste `INVALID` avec `AVATAR_NOT_FOUND`; un token composé
uniquement de `null` reste valide.

## 14. SuperAdmin preview requirements

La page future `/admin/scenario-packs` lit le manifeste avant exécution. Elle
affiche au minimum :

### Résumé

- metadata, version, locale, état et digest ;
- nom et slug **proposé** de la sandbox ;
- nombres de users, Boucles, Dossiers, root documents/articles/files,
  messages, demandes, offres, events, polls, decisions, roadmap items ;
- nombres de modules, sequences, progress, assignments et submissions ;
- résultat VALID/INVALID et nombre d'erreurs.

### Vues détaillées

1. Personnes : identité fictive, rôle Organization, disponibilité, avatar et
   résumé du profil IA ;
2. Boucles + membres : type, owner, rôles, visibilité et accès ;
3. Dossiers / documents : hiérarchie, rattachement, auteur, format et taille ;
4. ChatLoop : ordre chronologique, auteur, replies et contenu ;
5. Entraide : categories/skills, demandes, offres et mises en avant ;
6. Collaboration : polls, events, decisions et roadmap ;
7. Formation : modules, sequences, progress, assignments et submissions ;
8. JSON brut : source exacte, copie/export et chemins d'erreurs surlignés.

Le preview n'affiche jamais le `proposed_slug` comme tenant déjà sélectionné.
Il dit explicitement « nouvelle sandbox ». Avant Validate, il montre le digest
qui sera approuvé. Avant Load, il répète les compteurs, le digest et le fait que
la création est une écriture métier.

## 15. Import/export/duplicate contract

### Import

- entrée : JSON brut uniquement ; un bloc Markdown ```json est invalide ;
- parsing strict : pas de commentaire, trailing comma, `NaN`, `Infinity`, clé
  dupliquée ou BOM ;
- effet : stockage administratif en `DRAFT` et rapport de parsing ;
- aucun appel au Scenario Pack loader, aucun fichier, aucun objet métier ;
- une erreur de parse conserve le texte pour correction mais aucun manifeste
  structuré n'est déclaré valide.

### Export / Copy token

- sortie : le document conforme à l'enveloppe de section 6 ;
- JSON UTF-8, indentation de deux espaces, newline final ;
- ordre canonique des propriétés selon la section 6, puis ordre déclaré des
  collections ;
- aucune propriété système (`state`, digest, timestamps d'admin, DB IDs,
  Organization réelle, ownership registry) ;
- importer l'export doit produire le même digest canonique.

### Duplicate

Le SuperAdmin fournit un nouvel `id`, un nouveau `name` et un nouveau
`organization.proposed_slug`. BouclePro copie le contenu, fixe `version` à
`1.0.0`, conserve `schema_version`, remplace les trois valeurs demandées et
crée un `DRAFT`. Les stable keys internes restent inchangées car elles sont
locales au nouveau pack. Aucun load, approbation ou UUID n'est copié.

## 16. Example AMT manifest

Cet exemple est **valide et réaliste**, mais n'exerce pas toutes les variantes
facultatives du langage. Il contient deux formateurs, vingt stagiaires, une
Boucle training, une Boucle general d'entraide, des contenus, échanges,
objets de collaboration et états de formation. Toutes les références utilisent
des keys ; aucun UUID n'est fourni.

```json
{
  "schema_version": "1.0",
  "id": "amt-formation-ia",
  "version": "1.0.0",
  "name": "AMT — Formation IA",
  "description": "Communauté fictive de formateurs et stagiaires découvrant les usages responsables de l'IA.",
  "purpose": "Démontrer une formation, son entraide et son historique avant validation humaine.",
  "locale": "fr",
  "assets": { "avatar_bank": "fictional-avatars-v1" },
  "organization": {
    "name": "AMT — Formation IA",
    "proposed_slug": "amt-formation-ia",
    "description": "Sandbox fictive AMT pour démonstration et recette.",
    "locale": "fr"
  },
  "users": [
    { "key": "trainer-1", "first_name": "Nora", "name": "Martin", "email": "nora.martin@amt-demo.test", "bio": "Formatrice en pédagogie numérique.", "available": true, "location": "Lyon", "avatar": "female-03", "organization_role": "admin", "member_ai_profile": { "status": "published", "summary": "Accompagne les équipes dans l'adoption responsable de l'IA.", "service_scope": "Pédagogie et cadrage des usages.", "experience_context": "Formation professionnelle fictive.", "target_audience": ["formateurs", "équipes pédagogiques"], "problems_helped": ["concevoir une séquence IA"], "skills": ["pédagogie", "IA responsable"], "help_types": ["atelier", "relecture"], "boundaries": ["pas de conseil juridique"], "preferred_contact_action": "message", "tone": "pedagogical" } },
    { "key": "trainer-2", "first_name": "Samir", "name": "Diallo", "email": "samir.diallo@amt-demo.test", "bio": "Formateur technique.", "available": true, "location": "Paris", "avatar": "male-02", "organization_role": "member", "member_ai_profile": { "status": "published", "summary": "Aide à prototyper et à vérifier des assistants IA.", "service_scope": "Prototypage et évaluation.", "experience_context": "Ateliers techniques fictifs.", "target_audience": ["stagiaires"], "problems_helped": ["évaluer une réponse IA"], "skills": ["prompting", "évaluation"], "help_types": ["coaching"], "boundaries": ["aucune donnée sensible"], "preferred_contact_action": "service_request", "tone": "direct" } },
    { "key": "student-01", "first_name": "Alice", "name": "Bernard", "email": "student-01@amt-demo.test", "bio": "Responsable de formation.", "available": true, "location": "Grenoble", "avatar": "female-01", "organization_role": "member", "member_ai_profile": { "status": "published", "summary": "Partage des pratiques de conception pédagogique.", "service_scope": null, "experience_context": null, "target_audience": ["formateurs"], "problems_helped": ["structurer un atelier"], "skills": ["ingénierie pédagogique"], "help_types": ["pair review"], "boundaries": [], "preferred_contact_action": "message", "tone": "warm" } },
    { "key": "student-02", "first_name": "Benoît", "name": "Caron", "email": "student-02@amt-demo.test", "bio": "Chef de projet.", "available": true, "location": "Nantes", "avatar": "male-01", "organization_role": "member", "member_ai_profile": null },
    { "key": "student-03", "first_name": "Chloé", "name": "Durand", "email": "student-03@amt-demo.test", "bio": "Conceptrice pédagogique.", "available": false, "location": "Lille", "avatar": "female-02", "organization_role": "member", "member_ai_profile": null },
    { "key": "student-04", "first_name": "David", "name": "Etienne", "email": "student-04@amt-demo.test", "bio": "Facilitateur.", "available": true, "location": null, "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-05", "first_name": "Emma", "name": "Faure", "email": "student-05@amt-demo.test", "bio": "Chargée de mission.", "available": true, "location": "Dijon", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-06", "first_name": "Farid", "name": "Giraud", "email": "student-06@amt-demo.test", "bio": "Consultant.", "available": true, "location": "Tours", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-07", "first_name": "Gaëlle", "name": "Henry", "email": "student-07@amt-demo.test", "bio": "Tutrice.", "available": false, "location": null, "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-08", "first_name": "Hugo", "name": "Imbert", "email": "student-08@amt-demo.test", "bio": "Coordinateur.", "available": true, "location": "Rennes", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-09", "first_name": "Inès", "name": "Jacob", "email": "student-09@amt-demo.test", "bio": "Médiatrice.", "available": true, "location": "Rouen", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-10", "first_name": "Jules", "name": "Klein", "email": "student-10@amt-demo.test", "bio": "Enseignant.", "available": true, "location": "Metz", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-11", "first_name": "Kenza", "name": "Lopez", "email": "student-11@amt-demo.test", "bio": "Formatrice.", "available": true, "location": "Montpellier", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-12", "first_name": "Léo", "name": "Moreau", "email": "student-12@amt-demo.test", "bio": "Designer pédagogique.", "available": false, "location": "Bordeaux", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-13", "first_name": "Maya", "name": "Nguyen", "email": "student-13@amt-demo.test", "bio": "Responsable RH.", "available": true, "location": null, "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-14", "first_name": "Noé", "name": "Olivier", "email": "student-14@amt-demo.test", "bio": "Animateur.", "available": true, "location": "Caen", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-15", "first_name": "Olivia", "name": "Petit", "email": "student-15@amt-demo.test", "bio": "Référente numérique.", "available": true, "location": "Amiens", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-16", "first_name": "Paul", "name": "Quentin", "email": "student-16@amt-demo.test", "bio": "Chef d'équipe.", "available": false, "location": null, "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-17", "first_name": "Rania", "name": "Roux", "email": "student-17@amt-demo.test", "bio": "Documentaliste.", "available": true, "location": "Orléans", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-18", "first_name": "Sacha", "name": "Simon", "email": "student-18@amt-demo.test", "bio": "Accompagnateur.", "available": true, "location": "Nancy", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-19", "first_name": "Tania", "name": "Thomas", "email": "student-19@amt-demo.test", "bio": "Chargée de formation.", "available": true, "location": "Reims", "avatar": null, "organization_role": "member", "member_ai_profile": null },
    { "key": "student-20", "first_name": "Victor", "name": "Urbain", "email": "student-20@amt-demo.test", "bio": "Responsable qualité.", "available": true, "location": "Poitiers", "avatar": null, "organization_role": "member", "member_ai_profile": null }
  ],
  "loops": [
    { "key": "training-main", "name": "Formation IA — promotion septembre", "description": "Parcours principal de la promotion.", "type": "training", "owner": "trainer-1", "visibility": "private", "access_mode": "invitation", "root_dossier": "training-docs" },
    { "key": "help-general", "name": "Entraide AMT", "description": "Questions, offres et retours entre participants.", "type": "general", "owner": "trainer-2", "visibility": "private", "access_mode": "invitation", "root_dossier": "help-docs" }
  ],
  "memberships": [
    { "loop": "training-main", "user": "trainer-1", "role": "owner" },
    { "loop": "training-main", "user": "trainer-2", "role": "facilitator" },
    { "loop": "help-general", "user": "trainer-2", "role": "owner" },
    { "loop": "help-general", "user": "trainer-1", "role": "facilitator" },
    { "loop": "training-main", "user": "student-01", "role": "member" },
    { "loop": "training-main", "user": "student-02", "role": "member" },
    { "loop": "training-main", "user": "student-03", "role": "member" },
    { "loop": "training-main", "user": "student-04", "role": "member" },
    { "loop": "training-main", "user": "student-05", "role": "member" },
    { "loop": "training-main", "user": "student-06", "role": "member" },
    { "loop": "training-main", "user": "student-07", "role": "member" },
    { "loop": "training-main", "user": "student-08", "role": "member" },
    { "loop": "training-main", "user": "student-09", "role": "member" },
    { "loop": "training-main", "user": "student-10", "role": "member" },
    { "loop": "training-main", "user": "student-11", "role": "member" },
    { "loop": "training-main", "user": "student-12", "role": "member" },
    { "loop": "training-main", "user": "student-13", "role": "member" },
    { "loop": "training-main", "user": "student-14", "role": "member" },
    { "loop": "training-main", "user": "student-15", "role": "member" },
    { "loop": "training-main", "user": "student-16", "role": "member" },
    { "loop": "training-main", "user": "student-17", "role": "member" },
    { "loop": "training-main", "user": "student-18", "role": "member" },
    { "loop": "training-main", "user": "student-19", "role": "member" },
    { "loop": "training-main", "user": "student-20", "role": "member" },
    { "loop": "help-general", "user": "student-01", "role": "member" },
    { "loop": "help-general", "user": "student-02", "role": "member" },
    { "loop": "help-general", "user": "student-03", "role": "member" },
    { "loop": "help-general", "user": "student-04", "role": "member" },
    { "loop": "help-general", "user": "student-05", "role": "member" },
    { "loop": "help-general", "user": "student-06", "role": "member" },
    { "loop": "help-general", "user": "student-07", "role": "member" },
    { "loop": "help-general", "user": "student-08", "role": "member" },
    { "loop": "help-general", "user": "student-09", "role": "member" },
    { "loop": "help-general", "user": "student-10", "role": "member" },
    { "loop": "help-general", "user": "student-11", "role": "member" },
    { "loop": "help-general", "user": "student-12", "role": "member" },
    { "loop": "help-general", "user": "student-13", "role": "member" },
    { "loop": "help-general", "user": "student-14", "role": "member" },
    { "loop": "help-general", "user": "student-15", "role": "member" },
    { "loop": "help-general", "user": "student-16", "role": "member" },
    { "loop": "help-general", "user": "student-17", "role": "member" },
    { "loop": "help-general", "user": "student-18", "role": "member" },
    { "loop": "help-general", "user": "student-19", "role": "member" },
    { "loop": "help-general", "user": "student-20", "role": "member" }
  ],
  "dossiers": [
    { "key": "training-docs", "name": "Supports de formation", "owner": "trainer-1", "loop": "training-main", "parent": null, "visibility": "loop", "root_document": { "title": "Manifeste de la formation", "author": "trainer-1", "format": "markdown", "content": "# Notre cadre\n\nNous expérimentons, vérifions et gardons un humain responsable." } },
    { "key": "help-docs", "name": "Ressources d'entraide", "owner": "trainer-2", "loop": "help-general", "parent": null, "visibility": "loop", "root_document": { "title": "Charte d'entraide", "author": "trainer-2", "format": "markdown", "content": "# Entraide\n\nDécrire le contexte, ce qui a été essayé et la limite rencontrée." } }
  ],
  "articles": [
    { "key": "article-charte-ia", "dossier": "training-docs", "author": "trainer-1", "title": "Charte d'usage responsable", "summary": "Les règles de travail de la promotion.", "status": "published", "audience": "loop", "format": "html", "content": "<h2>Règles</h2><ul><li>Vérifier les faits.</li><li>Ne pas exposer de données sensibles.</li></ul>", "published_offset_minutes": -20160 }
  ],
  "files": [
    { "key": "file-guide-prompt", "dossier": "training-docs", "uploaded_by": "trainer-2", "name": "guide-prompt.md", "media_type": "text/markdown", "content": "# Guide\n\nUn bon prompt donne le contexte, la tâche et les critères." },
    { "key": "file-cas-pratique", "dossier": "training-docs", "uploaded_by": "trainer-1", "name": "cas-pratique.html", "media_type": "text/html", "content": "<h2>Cas pratique</h2><p>Comparer deux réponses et documenter les écarts.</p>" }
  ],
  "messages": [
    { "key": "welcome", "type": "loop_message", "loop": "training-main", "author": "trainer-1", "body": "Bienvenue. Commencez par la charte puis présentez votre cas d'usage.", "format": "plain", "order": 0, "offset_minutes": -10080, "reply_to": null },
    { "key": "student-question", "type": "loop_message", "loop": "training-main", "author": "student-01", "body": "Comment vérifier une réponse qui cite une source inconnue ?", "format": "plain", "order": 1, "offset_minutes": -8640, "reply_to": "welcome" },
    { "key": "trainer-answer", "type": "loop_message", "loop": "training-main", "author": "trainer-2", "body": "Ouvre la source, vérifie l'auteur et la date, puis distingue le fait de l'interprétation.", "format": "plain", "order": 2, "offset_minutes": -8580, "reply_to": "student-question" },
    { "key": "help-intro", "type": "loop_message", "loop": "help-general", "author": "trainer-2", "body": "Utilisez cette Boucle pour demander ou proposer une aide concrète.", "format": "plain", "order": 0, "offset_minutes": -7200, "reply_to": null }
  ],
  "categories": [
    { "key": "ai-practice", "name": "Pratiques IA", "color": "#4F46E5" }
  ],
  "skills": [
    { "key": "prompt-review", "category": "ai-practice", "name": "Relecture de prompts" },
    { "key": "source-check", "category": "ai-practice", "name": "Vérification de sources" }
  ],
  "service_requests": [
    { "key": "request-source-check", "author": "student-01", "title": "Relire ma méthode de vérification", "description": "Je cherche un regard sur ma grille de vérification des sources.", "category": "ai-practice", "delivery_mode": "remote", "budget_min": 10, "budget_max": 20, "deadline_day_offset": 14, "status": "open", "highlight_in_loop": "help-general" }
  ],
  "services": [
    { "key": "offer-prompt-clinic", "author": "trainer-2", "title": "Clinique de prompt", "description": "Une session courte pour relire un prompt et son protocole d'évaluation.", "category": "ai-practice", "skills": ["prompt-review", "source-check"], "delivery_mode": "remote", "points_cost": 15, "status": "active", "highlight_in_loop": "help-general" }
  ],
  "polls": [
    { "key": "poll-next-workshop", "loop": "training-main", "author": "trainer-1", "question": "Quel thème approfondir au prochain atelier ?", "description": null, "selection_type": "single", "status": "open", "options": [{ "key": "sources", "label": "Vérifier les sources" }, { "key": "prompts", "label": "Écrire de meilleurs prompts" }], "votes": [{ "user": "student-01", "options": ["sources"] }, { "user": "student-02", "options": ["prompts"] }] }
  ],
  "events": [
    { "key": "event-workshop-2", "loop": "training-main", "author": "trainer-1", "title": "Atelier 2 — Évaluer une réponse", "description": "Mise en pratique en petits groupes.", "format": "online", "starts_offset_minutes": 10080, "duration_minutes": 120, "timezone": "Europe/Paris", "location": null, "meeting_url": "https://meet.example.test/amt-workshop-2", "visibility": "loop", "status": "scheduled", "responses": [{ "user": "student-01", "response": "going" }, { "user": "student-03", "response": "maybe" }] }
  ],
  "decisions": [
    { "key": "decision-human-review", "loop": "training-main", "author": "trainer-1", "title": "Toute production partagée sera relue par un humain", "rationale": "La promotion veut distinguer assistance et délégation de responsabilité.", "decided_day_offset": -7, "message": "trainer-answer", "supersedes": null }
  ],
  "roadmap_items": [
    { "key": "roadmap-source-grid", "loop": "training-main", "created_by": "trainer-1", "title": "Publier la grille de vérification", "description": "Version relue pendant l'atelier 2.", "status": "in_progress", "position": 0, "assignees": ["trainer-2", "student-01"], "due_day_offset": 10, "decision": "decision-human-review" }
  ],
  "training": {
    "modules": [
      { "key": "module-foundations", "loop": "training-main", "title": "Fondations", "summary": "Comprendre les limites et formuler une demande.", "position": 0, "created_by": "trainer-1" },
      { "key": "module-evaluation", "loop": "training-main", "title": "Évaluation", "summary": "Vérifier faits, sources et utilité.", "position": 1, "created_by": "trainer-2" }
    ],
    "sequences": [
      { "key": "sequence-charter", "module": "module-foundations", "title": "Lire la charte", "position": 0, "requires_validation": false, "created_by": "trainer-1", "content": { "type": "article", "article": "article-charte-ia" } },
      { "key": "sequence-prompt", "module": "module-foundations", "title": "Structurer un prompt", "position": 1, "requires_validation": false, "created_by": "trainer-2", "content": { "type": "file", "file": "file-guide-prompt" } },
      { "key": "sequence-evaluate", "module": "module-evaluation", "title": "Évaluer une réponse", "position": 0, "requires_validation": true, "created_by": "trainer-2", "content": { "type": "text", "body": "Comparez la réponse aux sources et notez les incertitudes." } }
    ],
    "progress": [
      { "sequence": "sequence-charter", "user": "student-01", "status": "completed", "started_offset_minutes": -7200, "completed_offset_minutes": -7100, "validated_by": null, "validated_offset_minutes": null, "unlocked_by": null, "unlocked_offset_minutes": null },
      { "sequence": "sequence-evaluate", "user": "student-01", "status": "validated", "started_offset_minutes": -3000, "completed_offset_minutes": -2500, "validated_by": "trainer-2", "validated_offset_minutes": -2400, "unlocked_by": "trainer-1", "unlocked_offset_minutes": -3100 },
      { "sequence": "sequence-prompt", "user": "student-02", "status": "in_progress", "started_offset_minutes": -1440, "completed_offset_minutes": null, "validated_by": null, "validated_offset_minutes": null, "unlocked_by": null, "unlocked_offset_minutes": null }
    ],
    "assignments": [
      { "key": "assignment-source-review", "loop": "training-main", "sequence": "sequence-evaluate", "title": "Analyser une réponse IA", "brief": "Rendre une analyse courte avec deux sources vérifiées.", "due_offset_minutes": 20160, "position": 0, "created_by": "trainer-2" }
    ],
    "submissions": [
      { "assignment": "assignment-source-review", "user": "student-01", "body": "J'ai distingué trois affirmations et vérifié deux sources.", "file": null, "status": "validated", "submitted_offset_minutes": -2200, "feedback": "Analyse claire ; conserver cette méthode.", "reviewed_by": "trainer-2", "reviewed_offset_minutes": -2000 },
      { "assignment": "assignment-source-review", "user": "student-02", "body": "Brouillon de la grille d'analyse.", "file": null, "status": "draft", "submitted_offset_minutes": null, "feedback": null, "reviewed_by": null, "reviewed_offset_minutes": null }
    ]
  }
}
```

## 17. Acceptance criteria

La future implémentation est conforme lorsque :

1. elle accepte l'exemple AMT ci-dessus et rejette chaque mutation qui viole
   une règle normative ;
2. deux validations du même JSON produisent le même verdict, le même digest et
   la même liste ordonnée d'erreurs ;
3. Import et Validate ne créent aucune donnée métier ni fichier ;
4. aucun champ ne permet de choisir une Organization existante ;
5. toutes les références sont résolues avant Load ;
6. le preview affiche tous les compteurs et détails requis avant exécution ;
7. Load est impossible sans état `VALID`, confirmation humaine et digest
   inchangé ;
8. le loader utilise les primitives métier canoniques et inscrit chaque entité
   dans le registrar ;
9. reset et delete restent bornés au registre du chargement ;
10. les quatre packs PHP existants continuent à fonctionner sans conversion ;
11. aucun objet exclu, résultat dérivé ou appel provider n'est produit depuis
    une déclaration du manifeste ;
12. un test de sécurité prouve qu'un payload contenant `organization_id`,
    `target_organization: "main"` ou `tenant: "customer-x"` est rejeté avant
    toute écriture ;
13. un test mental avec une IA sans connaissance Laravel permet de construire
    un AMT valide en n'utilisant que cette spec ;
14. un développeur peut implémenter le Validator sans choisir de nouveaux noms,
    enums, limites, relations ou règles de nullabilité.

## 18. Explicitly excluded objects

Les propriétés ou objets suivants sont interdits dans tout Manifest V1.

### Données dérivées ou système — jamais déclarables

- `ai_interactions` et toute interaction IA persistée ;
- traces IA et provider calls ;
- embeddings ;
- `dossier_chunks` ;
- `DerivedKnowledgeNote` ;
- résultats RAG, citations calculées ou réponses d'agent ;
- notifications système ;
- referrals système ;
- `scenario_pack_entities` ;
- `scenario_pack_loads`.

Ces données sont produites, si nécessaire, par le vrai produit et ses
primitives après le Load. Elles ne sont jamais acceptées comme vérité fournie
par une IA externe.

### Hors scope V1

- `Transaction` ;
- `PointLedger` ;
- `Badge` ;
- `LoopInvitation` / `JoinRequest` ;
- `CustomLoopType` ;
- réactions emoji ;
- `ArticleSeries` ;
- messagerie Transaction ;
- reviews ;
- `CourseQuiz`, questions, options, attempts et scores, reportés à V1.1.

Une clé équivalente en snake_case, camelCase ou casse différente reste
interdite. Un champ inconnu qui tente d'encoder un de ces objets retourne
`FORBIDDEN_OBJECT`, pas une tolérance silencieuse.

## 19. Migration/compatibility with existing PHP packs

**FACT.** Quatre definitions PHP sont actuellement enregistrées :

- `ArtSciLabDemoPack` ;
- `Test20260822DogfoodingPack` ;
- `ArtSciLabEnglishPack` ;
- `AiLabPack`.

Elles implémentent `ScenarioPackDefinition` et continuent à être résolues par
le catalogue actuel. Manifest V1 n'en change ni le code, ni l'identité, ni la
cible, ni le registre.

**DECISION.** La migration est incrémentale :

1. ajouter ultérieurement le parser/Validator et le stockage administratif ;
2. ajouter `ManifestScenarioPack` comme nouvelle implémentation de
   `ScenarioPackDefinition` ;
3. livrer le premier nouveau pack AMT en Manifest V1 ;
4. observer load/reset/delete et la sécurité tenant ;
5. envisager ensuite la conversion d'ArtSciLab English dans une TASK séparée.

Il n'y a ni big bang, ni obligation de convertir un pack PHP. Les entity types
internes historiques peuvent rester nécessaires à leur registrar, mais ils ne
deviennent jamais le vocabulaire public du manifeste. Pour les nouveaux
messages, le vocabulaire est `messages` / `loop_message`.

## 20. FACT / DECISION / OPEN QUESTION review

### FACTS vérifiés contre le code courant

- le loader applique un `ScenarioPackDefinition` dans une Organization objet,
  sous transaction et verrou Organization ;
- l'allowlist Organization précède load/reset/remove ;
- le registrar refuse le cross-tenant, trace created/reused et protège les
  chemins de stockage ;
- reset réapplique puis purge seulement les orphelins enregistrés ;
- remove purge dans l'ordre inverse et respecte l'ownership ;
- les types de Loop `general`, `project`, `coaching` et `training` existent ;
- les rôles canoniques sont `owner`, `facilitator`, `member` ;
- les services métier canoniques existent pour les principales familles CORE
  et TRAINING décrites ici ;
- les états de progression `available`/`unavailable` sont calculés, tandis que
  les cinq états listés en section 12 sont stockés ;
- une CourseSubmission fichier référence un DossierFile existant ;
- quatre packs PHP restent enregistrés.

### DECISIONS V1

- JSON fermé, strict, version `1.0`, sans UUID ni cible tenant ;
- sandbox toujours nouvelle, slug seulement proposé ;
- stable keys et références typées ;
- limites chiffrées et erreurs déterministes ;
- dates relatives au Load ;
- import séparé de la validation, elle-même séparée du Load ;
- approbation humaine attachée au digest ;
- avatars locaux optionnels et versionnés ;
- CORE et TRAINING dans le même langage ;
- CourseQuiz reporté à V1.1 ;
- compatibilité ascendante par adaptateur, sans conversion des packs PHP.

### OPEN QUESTIONS

**Aucune question produit ne bloque Manifest V1 ou l'écriture de son
Validator.** Les choix de persistance des brouillons, de composants UI, de
permissions SuperAdmin détaillées et de packaging physique de la future banque
d'avatars appartiennent aux TASKs d'implémentation ; ils ne changent pas le
contrat JSON défini ici.
