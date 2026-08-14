# Architecture IA — fondation, corrélation, process, trace

Décrit ce que P1-1/P1-2/P1-3 (observabilité) et P3 (fondation `App\Ai`) ont
livré. Pour la vision cible (mycélium,
fédération, mémoire, RAG), voir `docs/architecture/05-AI_MYCELIUM_ARCHITECTURE.md`
(DRAFT, non autoritaire sur l'implémentation). Pour le détail des
intégrations produit existantes, voir `docs/architecture/04-IMPLEMENTATION_IA_BOUCLEPRO.md`.

## `correlation_id` — une opération BouclePro

`correlation_id` identifie **une opération métier**, jamais un appel LLM.
Une opération peut produire plusieurs appels IA et plusieurs écritures de
trace : toutes partagent la même corrélation.

```
correlation_id BouclePro
    ├── appel provider A
    ├── appel provider B (ex : invocationId SDK A)
    ├── invocationId SDK B
    └── écritures de trace (ai_interactions, admin_ai_interactions, ...)
```

Implémentation : `App\Support\Ai\AiCorrelation`, appuyée sur
`Illuminate\Support\Facades\Context` (liaison `scoped` par requête/job).

- `AiCorrelation::id()` — corrélation courante, créée à la volée si absente.
  C'est l'appel à utiliser sur un site d'écriture.
- `AiCorrelation::start()` — force une nouvelle opération.
- `AiCorrelation::bind($id)` — réadopte une corrélation existante
  (propagation asynchrone).
- Format : UUID v4, colonne `uuid` nullable sur les trois tables de trace,
  additive, sans backfill (une ligne historique a `correlation_id = NULL`,
  jamais une corrélation fabriquée).

### Propagation à travers une frontière asynchrone

Patron unique, réutilisé identique à chaque job qui touche l'IA
(`App\Jobs\GenerateAiAgentResponse` — P1-1 — et `App\Jobs\IndexDossierArticleChunks`
— P1-3) :

1. le constructeur du job fige `$correlationId` **au DISPATCH**
   (`$correlationId ?? AiCorrelation::id()`), donc dans l'opération d'origine ;
2. `handle()` réadopte via `AiCorrelation::bind($this->correlationId)` en
   première ligne — le worker exécute chaque job dans un scope neuf, la
   réadoption explicite est ce qui empêche une corrélation arbitraire ;
3. un retry Laravel rejoue le même payload sérialisé → même corrélation ;
4. deux jobs distincts réadoptent chacun leur propre corrélation, aucun
   mélange.

## `invocationId` (SDK) — un appel au Laravel AI SDK

`invocationId` est un concept **du SDK officiel** (`laravel/ai`), pas de
BouclePro. Il identifie un seul appel réseau vers un provider. Généré une
fois par appel (`Str::uuid7()`, `Providers/Concerns/GeneratesEmbeddings.php`),
partagé entre l'événement "avant" et l'événement "après" du même appel.

**Relation stricte, jamais interchangeable** :

- une `correlation_id` peut contenir plusieurs `invocationId` ;
- `invocationId` ne remplace **jamais** `correlation_id` ;
- `invocationId` n'est stocké nulle part dans les colonnes dédiées des trois
  tables de trace — il vit dans le champ `metadata` JSON
  (`metadata.sdk_invocation_id`), aux côtés du reste du contexte structuré.

Exemples concrets livrés :

```
correlation_id (une indexation d'article — embeddings, P1-3)
    └── invocationId SDK unique (un seul batch Embeddings::for($chunks))

correlation_id (un résumé de Boucle — texte, P3)
    └── invocationId SDK unique (un seul Agent::prompt())
```

Une régénération déclenchée par l'utilisateur est une **nouvelle opération** :
nouvelle requête HTTP, donc nouvelle `correlation_id`, et nouvel
`invocationId`. Les deux identifiants changent, mais jamais l'un pour l'autre.

## `process` — identifiant technique stable

`process` répond à « quel traitement BouclePro a produit cette trace ? ».
Jamais un texte traduit, jamais un libellé d'interface, jamais dépendant de
la locale utilisateur. Implémentation : `App\Support\Ai\AiProcess`, qui
cartographie les concepts déjà présents dans le code (`ai_interactions.feature`,
`admin_ai_interactions.scenario_id`, type d'usage member profile) — ce n'est
**pas** le `CapabilityRegistry` de P3 : aucune capacité, aucun routage,
aucune politique, juste une normalisation stable pour l'observabilité.

- `AiProcess::fromFeature(?string $feature)` — pour `ai_interactions`.
- `AiProcess::fromScenarioId(?string $scenarioId)` — pour `admin_ai_interactions`.
- Repli pour un identifiant non cartographié : normalisation déterministe
  (minuscules, suffixe de locale retiré, tronqué à 100 caractères) — jamais
  une taxonomie inventée.

## La fondation `App\Ai` (P3)

Une seule architecture IA, plusieurs capabilities, un contexte différent selon
l'action. Ce n'est pas « l'IA du Blog » et « l'IA de ChatLoop » : ce sont deux
capabilities du même socle.

| Classe | Rôle | Ce qu'elle ne fait pas |
|---|---|---|
| `App\Ai\ContexteIa` | identifiants **déjà autorisés** d'une opération : `organizationId` (obligatoire), `userId`, `loopId`, `locale`, `capability`, `correlationId`, `source` | ne porte aucun modèle Eloquent — il ne peut donc ni recharger, ni élargir, ni contourner la portée établie par l'appelant. Aucun `community_id` |
| `App\Ai\Constitution` | cadre commun (v1), placé **systématiquement en tête** du prompt | n'est jamais surchargeable par un prompt éditable |
| `App\Ai\CapabilityRegistry` | déclare ce qu'une capability a le droit de faire : `allowedScopes`, `allowedSources`, `canWrite`, `requiresHumanConfirmation`, `maxOutput`, `promptKey` | default deny : une capability inconnue lève, elle ne retombe sur rien |
| `App\Ai\PromptRepository` | compose Constitution -> instruction capability -> contexte autorisé | `AdminAiPrompt` fournit l'instruction, il **ne remplace jamais** la Constitution |
| `App\Ai\ProviderResolver` | `resolve(capability, ContexteIa)` -> provider + modèle explicites | n'appelle rien, ne route pas, ne benchmarke pas, ne lit pas le catalogue tarifaire, ignore les clés tenant. Config absente ou incohérente : `DomainException`, jamais de repli silencieux |

Les identifiants de `ContexteIa` sont des **UUID** : `Organization`, `User` et
`Loop` utilisent tous `HasUuids`.

### `can_write` — l'IA propose, l'humain publie

`canWrite = false` signifie que la capability peut lire les sources autorisées,
appeler l'IA et déposer ses traces techniques, mais **ne peut pas créer de
contribution métier visible**. C'est la traduction technique de la Constitution :
« L'humain décide avant toute publication ou action durable. »

`loop_summary` est la première capability à respecter cette règle : son résumé
n'est plus publié en `LoopMessage`, il est relu depuis sa trace
`ai_interactions`. Une capability qui doit légitimement publier (répondre dans
une Boucle) portera `canWrite = true` **avec**
`requiresHumanConfirmation = true` — l'IA prépare, l'humain valide.

### Convention de clé de prompt (décision, non encore appliquée au runtime)

Cible : un `prompt_key` métier **stable et non localisé**, résolu ainsi :

    {prompt_key}_{locale}  ->  {prompt_key}  ->  fallback

Le palier `_fr` codé en dur dans `ChatLoopAiService::resolvePrompt()` est un
repli de compatibilité legacy, pas la cible. Il rend le français prioritaire
sur le prompt neutre. À reprendre lors des migrations, sans modifier
`AdminAiPrompt`.

## Le Context Builder (P3)

`App\Ai\Context\ContextBuilder` repond a une seule question :

> « De quelles informations autorisees cette capability a-t-elle besoin
> maintenant, pour cet utilisateur, dans cette Organization ? »

    ContextBuilder::build(ContexteIa, CapabilityDefinition): ContexteBorne

`ContexteBorne` porte `text`, `provenance`, `charBudget`, `sourcesUsed` et
`sourcesDenied`.

### L'autorite, c'est la capability

`CapabilityDefinition::$allowedSources` decide. Une source non declaree n'est
pas filtree apres coup : **elle n'est jamais interrogee**. C'est ce qui empeche
une capability de gagner l'acces a des donnees simplement parce qu'une source a
ete ajoutee au builder.

Une source declaree mais inaccessible apparait dans `sourcesDenied` avec une
raison technique bornee — jamais un extrait de la ressource refusee, jamais un
message confirmant qu'elle existe dans une autre Organization. Un contexte
ampute doit se voir ; il ne doit rien laisser fuir.

### Sources disponibles

| Source | Contenu | Portee |
|---|---|---|
| `loop.messages` | les N derniers messages non supprimes, ordre chronologique, prefixes de leur auteur, encadres comme **contenu non fiable** | Boucle du contexte, dont l'Organization est verifiee dans la source elle-meme |
| `user.loops` | identifiant, nom, type et `tagline` des Boucles **dont l'utilisateur est membre actif** | Organization du contexte, `status = active` |

`user.loops` n'est declaree par aucune capability aujourd'hui : elle prepare la
suggestion de Boucle.

Son perimetre est **volontairement plus etroit** que le catalogue de
`LoopController::getAccessibleLoopsQuery()`, qui expose toutes les Boucles
actives de l'Organization pour permettre la decouverte. Un humain qui parcourt
un catalogue et une IA qui propose une destination n'ont pas le meme perimetre
legitime : l'IA ne propose que ce dont l'utilisateur est deja membre.

### Budget

`CapabilityDefinition::$maxOutput` borne ce que le modele **produit** ;
`$contextCharBudget` borne ce qu'on lui **donne**. Deux limites distinctes,
deux champs distincts. Le budget s'applique apres selection : mieux vaut trois
sources completes que dix tronquees.

Aucun appel LLM, aucune approximation de tokens par service externe : la
selection est deterministe et locale, donc reproductible en test.

### Ce qui n'est PAS encore supporte

Une seule capability le consomme (`loop_summary`), et deux sources existent.
Ni `dossier.chunks` (le retrieval semantique reste hors du builder), ni les
articles, profils, transactions, evenements, decisions, ni l'historique
`ai_interactions` comme contexte. La provenance vit en memoire dans
`ContexteBorne` et n'est pas persistee.

## L'API texte du Laravel AI SDK (v0.7.2)

Vérifiée par lecture directe de `vendor/laravel/ai/`, pas depuis la
documentation « latest ».

- **Appel** : `Agent::prompt($prompt, provider:, model:, timeout:)` via le
  trait `Laravel\Ai\Promptable`. Provider et modèle passés **explicitement** :
  `Provider::formatProviderAndModelList()` produit alors une liste à une seule
  entrée, ce qui exclut tout failover silencieux vers un provider que personne
  n'a choisi.
- **Réponse** : `AgentResponse { string $invocationId; string $text; Usage $usage; Meta $meta; }`.
- **Événements** : `PromptingAgent(invocationId, prompt)` et
  `AgentPrompted(invocationId, prompt, response)`. Comme pour les embeddings,
  **aucun événement d'échec n'existe**.
- **Fake officiel** : `Ai::fakeAgent(AgentClass::class, $responses)`, indexé
  **par nom de classe d'agent** — d'où l'intérêt d'une classe d'agent dédiée
  plutôt que `Laravel\Ai\agent()` anonyme, dont la clé serait partagée.
- **Aucun coût provider n'est exposé** : voir `OBSERVABILITE-COUTS.md`.

## Le RAG est une source, pas l'architecture

Le retrieval sémantique Dossiers (`dossier_chunks`, pgvector, isolation
`organization_id` + `dossier_id` + `embedding_provider/model`) est **une source
parmi d'autres** du futur Context Builder, pas l'architecture IA.

Corollaire : tout n'a pas besoin d'embeddings. Les messages d'une Boucle, les
profils, les demandes sont peu nombreux et déjà scopés — une requête directe
les récupère mieux qu'une recherche vectorielle, sans coût d'embedding ni
dérive de modèle. Les embeddings servent les corpus documentaires volumineux
et peu structurés.

## Les trois tables de trace

| Table | Rôle | Contrainte notable |
|---|---|---|
| `ai_interactions` | appels IA côté utilisateur (blog), texte complet prompt/réponse | `user_id` **NOT NULL**, `prompt` **NOT NULL** |
| `admin_ai_interactions` | scénarios admin/système, structurés (pas un texte de chat) | `user_id` **NULLABLE**, colonnes structurées (`input_excerpt`, `result_payload` JSON) |
| `member_ai_profile_interactions` | interactions de l'agent de profil membre | liée à `member_ai_profile_id` |

Les trois partagent `correlation_id` et `process` (P1-1), `cost_usd`/
`cost_unknown` (P1-2, voir `OBSERVABILITE-COUTS.md`).

### Pourquoi `admin_ai_interactions` a été réutilisée pour les embeddings SDK (P1-3)

Aucune capability ne devait être migrée en P1-3 — seule l'instrumentation
d'un usage déjà réel (embeddings Dossiers) servait de preuve d'intégration.
Vérification de fidélité avant tout choix (P1-3) : `ai_interactions` exige
un `user_id` — impossible pour un job d'indexation en arrière-plan sans
utilisateur réel ; `member_ai_profile_interactions` est hors sujet.
`admin_ai_interactions` accepte un `user_id` nul et a déjà un précédent
d'usage non-admin (`scenario_id = clarify_help_request`, déclenché par un
membre). **Zéro migration de schéma** n'a été nécessaire.

Nom de table trompeur pour cet usage, assumé et documenté dans TASK-1200 —
non corrigé (renommage hors périmètre P1).

## Limites connues du Laravel AI SDK v0.7.2

Vérifiées par lecture directe de `vendor/laravel/ai/src/Events/*.php` (28
événements installés), pas depuis la documentation "latest" (qui ne
correspond pas exactement à la version installée — elle cite des événements
absents et omet des événements présents).

- **Aucun événement d'échec n'existe.** `EmbeddingsGenerated` (et son
  équivalent pour les autres capacités) est dispatché via `tap()` sur la
  réponse **réussie** ; si l'appel lève une exception, `tap()` ne s'exécute
  jamais et rien n'est dispatché — ni `EmbeddingsGenerated`, ni un
  hypothétique `XxxFailed` (inexistant dans les 28 événements).
  `Laravel\Ai\Exceptions\AiException` est une exception nue, sans
  `invocationId`.
- **Conséquence directe pour toute future instrumentation** : le chemin de
  succès peut être automatique via les événements (`GeneratingX`/`XGenerated`),
  mais le chemin d'échec exige toujours un `try/catch` explicite au call
  site réel, qui doit retrouver l'`invocationId` en attente déposé par
  l'événement "avant" (pattern implémenté dans
  `App\Listeners\RecordSdkEmbeddingsInvocation`, via `Context`).

## Règle pour les futures migrations P3

Toute capability migrée vers `Agent`/`Promptable` du SDK :

1. **hérite automatiquement de l'observabilité de succès** si un listener
   écoute les événements correspondants (auto-découverte Laravel des
   listeners dans `app/Listeners/*`, par type de paramètre `handle()`) ;
2. **doit reproduire explicitement le `try/catch`** au call site pour tracer
   les échecs — sinon les échecs de cette capability resteront invisibles,
   silencieusement, alors que les succès seront tracés. Ce n'est pas une
   négligence à corriger plus tard : c'est une contrainte structurelle du
   SDK v0.7.2, à rappeler dans toute TASK de migration P3.
3. **ne doit jamais enregistrer explicitement un listener déjà présent dans
   `app/Listeners/`** via `Event::listen()` dans un ServiceProvider : Laravel
   l'auto-découvre déjà par le type-hint de `handle()`, et l'enregistrement
   explicite en plus produit une double trace (piège découvert et documenté
   dans TASK-1200, reproduit sur du code préexistant — `LoginListener`/`Login`).
