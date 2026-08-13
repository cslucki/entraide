# Observabilité — coûts et instrumentation SDK (P1)

Décrit le catalogue tarifaire (P1-2) et l'instrumentation des invocations
Laravel AI SDK (P1-3). Voir `ARCHITECTURE.md` pour le modèle de corrélation
sous-jacent.

## Principe directeur : `cost_unknown != cost 0`

Un tarif réellement nul (exécution locale, réponse rule-based) vaut
légitimement `0` **connu**. Un tarif inconnu ne vaut **jamais** `0` par
défaut — avant P1-2, un tarif absent produisait silencieusement un coût de
`0`, indiscernable d'un modèle gratuit.

## Catalogue tarifaire — une configuration versionnée, pas une table

`config/ai_pricing.php` : `version`, `currency`,
`models.<provider>.<modèle> = {input_per_1m, output_per_1m, free?}`,
`overrides.<provider>`. Pas de table métier, pas de quatrième registre — le
versionnement Git tient lieu d'historique.

Règles :

- entrée absente = tarif **inconnu**, jamais gratuit ;
- un taux nul exige `'free' => true` explicite. Une entrée `0/0` sans ce
  marqueur est rejetée comme coquille (garde-fou contre une virgule oubliée
  qui rendrait un modèle payant silencieusement gratuit) ;
- clé `'*'` réservée aux cas où l'absence de facturation est une propriété
  du provider (`ollama` en exécution locale, `rule_based`).

Catalogue livré **volontairement minimal** : seuls `openai/gpt-4o-mini`,
`openrouter/openai/gpt-4o-mini`, `ollama/*` et `rule_based/*` sont
renseignés. Aucun modèle d'embeddings n'y figure — un appel d'embeddings réel
retourne donc légitimement `cost_unknown = true`, ce qui est le comportement
voulu, pas une lacune à corriger en ajoutant un tarif au hasard.

## `cost_unknown` — un tri-état, jamais un booléen

| `cost_unknown` | Signification |
|---|---|
| `null` | statut non évalué (lignes antérieures à P1-2) |
| `false` | coût **connu**, y compris un `0` légitime |
| `true` | coût **non mesurable**, `cost_usd` vaut alors `NULL` |

Colonne `boolean NULLABLE`, sans backfill : un ancien `cost_usd = 0` n'est
réinterprété ni comme mesure certaine (`false` mentirait), ni comme inconnu
(`true` inventerait). `null` dit exactement ce qui est vrai.

**Lire un coût sans se tromper** : toujours `cost_usd` ET `cost_unknown`
ensemble.

```
cost_unknown = true   -> cost_usd IS NULL, aucune mesure
cost_unknown = false  -> cost_usd est une mesure, 0 compris
cost_unknown IS NULL  -> ligne d'avant P1-2, jamais évaluée
```

`SUM(cost_usd)` reste correct (les `NULL` sont ignorés par SQL) mais donne
un **plancher**, pas la dépense réelle, dès qu'il existe des lignes
`cost_unknown = true`. `php artisan ai:check-budgets` le signale
explicitement.

## Composants réutilisables

- `App\Support\Ai\AiUsage` — usage **observé**, distingue « 0 token
  rapporté » de « aucun usage rapporté ». Gère trois conventions API :
  `chat/completions` (`prompt_tokens`/`completion_tokens`),
  Responses API OpenAI (`input_tokens`/`output_tokens`), Ollama
  (`eval_count`, sortie seule). Ne fabrique jamais de zéro. `AiUsage::of()`
  accepte nativement un usage à sens unique (embeddings : entrée seule,
  jamais de sortie générée).
- `App\Support\Ai\AiCost` — verdict à trois états :
  `known(0.0)` (coût réellement nul), `known(x)` (coût mesuré),
  `unknown($reason)` (non mesurable). `traceAttributes()` renvoie les deux
  colonnes partagées par les trois tables de trace.
- `App\Support\Ai\AiPricingCatalog::cost($provider, $model, $usage)` — lit
  le catalogue et produit le verdict `AiCost`.

Ces trois classes sont **réutilisées à l'identique par P1-3**, sans aucune
modification — l'instrumentation des embeddings n'a nécessité ni nouveau
constructeur, ni nouvelle règle de tarification.

## Instrumentation des invocations Laravel AI SDK (P1-3)

Seul usage réel du SDK aujourd'hui : les embeddings Dossiers
(`App\Services\Dossiers\DossierChunkEmbeddingService`). Deux call sites :
indexation (`App\Jobs\IndexDossierArticleChunks`, un seul batch SDK pour
tous les chunks d'un article — un seul `invocationId` pour N chunks, le
contrat réel du batch) et recherche sémantique
(`DossierSemanticSearchService`, un seul texte).

### Événements retenus — le sous-ensemble minimal, pas les 28

`GeneratingEmbeddings` (ouverture, fixe l'horodatage de latence) et
`EmbeddingsGenerated` (résultat + usage, écrit la ligne de trace de succès).
Les 26 autres événements installés (dont `PromptingAgent`/`AgentPrompted`,
symétriques côté Agents) ne sont **pas** branchés : aucun call site Agent
réel n'existe dans le produit — les brancher aurait été de l'instrumentation
spéculative, invérifiable par un test réel. Voir `ARCHITECTURE.md` pour le
détail des limites du SDK et la règle P3.

### `App\Listeners\RecordSdkEmbeddingsInvocation`

Auto-découvert par Laravel (`app/Listeners/*`, type-hint `handle(GeneratingEmbeddings|EmbeddingsGenerated $event)`)
— **zéro enregistrement manuel** dans un ServiceProvider.

- Succès : lit `$event->response->tokens` (`AiUsage::of($tokens, null)`),
  calcule le verdict via `AiPricingCatalog::cost()`, écrit dans
  `admin_ai_interactions` avec `metadata.sdk_invocation_id`,
  `result_payload = {embedding_count, dimensions}` (jamais les vecteurs
  bruts, jamais le texte brut des chunks — déjà stocké dans
  `dossier_chunks.content`, dupliquer serait du bruit).
- Échec : `DossierChunkEmbeddingService::embed()` encadre l'appel SDK d'un
  `try/catch` (aucun événement SDK ne peut le faire), retrouve
  l'`invocationId` en attente et écrit `status = 'failed'`,
  `cost_unknown = true`, puis **relance l'exception d'origine inchangée** —
  l'instrumentation n'altère jamais le comportement fonctionnel.
- Sans contexte de trace posé par l'appelant (`organization_id`,
  `scenario_id`), rien n'est écrit — jamais de ligne orpheline hors tenant.

### Isolation Organization

`organization_id` et `process`/`scenario_id` ne sont portés par **aucun**
événement SDK — le SDK ignore tout du domaine BouclePro. L'appelant (qui
sait "pourquoi" il appelle) les dépose dans `Context` juste avant l'appel,
le listener les relit. `DossierChunkEmbeddingService::embed()` reste un pur
wrapper SDK, sans logique de trace.

Preuve testée (SQLite, `TASK1200SdkEmbeddingsInstrumentationTest`) : deux
Organizations, deux corrélations distinctes, aucune ligne de l'une n'est
jamais visible sous l'autre.
