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

## Garde économique (P2)

`App\Support\Ai\AiEconomicGuard` décide **avant** l'appel, et mesure après.

`authorize(Organization, process, provider, model, budgetMensuel, quotaUnknown)`
compte, sur le mois courant et **pour cette Organization seule** :

- la somme des coûts **connus** (`cost_unknown = false`) — refus si le budget
  mensuel est atteint ;
- le nombre d'appels au **coût non mesurable** (`cost_unknown = true`) — refus
  si le quota est atteint.

Un refus signifie qu'**aucun appel provider n'est émis**. Le quota UNKNOWN
existe parce qu'un coût non mesurable ne peut pas être plafonné par un budget :
sans lui, un modèle hors catalogue consommerait sans limite tout en restant
invisible dans la somme.

`finalize(provider, model, usage, ?coûtRapporté)` applique la priorité :

1. coût rapporté par le provider, **si** l'API l'expose ;
2. sinon `AiPricingCatalog` (usage observé x tarif connu) ;
3. sinon UNKNOWN.

Un échec d'appel est tracé avec `cost_usd = NULL` **et** `cost_unknown = NULL` —
l'état « statut non évalué ». Il reste ainsi visible sans peser ni sur le budget
ni sur le quota, dont les compteurs filtrent sur `false` et `true`.

### Le SDK texte v0.7.2 n'expose aucun coût provider

Vérifié par lecture de `vendor/laravel/ai/src/` : ni `Responses\Data\Usage`,
ni `Responses\Data\Meta` ne portent de coût. L'échelon 1 n'a donc pas de
source pour le texte — le catalogue devient le premier échelon effectif, UNKNOWN
reste le dernier. **Aucun appel HTTP secondaire n'est fait pour contourner le
SDK et récupérer un coût.**

Piège traité : `Usage` type ses compteurs `int` avec un défaut à `0`, et les
passerelles écrivent `$usage['prompt_tokens'] ?? 0`. Un bloc `usage` absent
devient donc `0/0`, indiscernable d'un vrai zéro **dans l'objet du SDK**.
`AiUsage::fromSdkTextTokens()` rétablit la distinction au seul endroit où elle
est encore décidable : une génération réelle ne consomme jamais 0 token
d'entrée ET 0 de sortie. Ce couple signe un usage non rapporté, donc UNKNOWN —
jamais un coût de 0.

### Couverture de la garde (état après TASK-1250)

Le paragraphe historique de P2 (« la garde ne couvre que `loop_summary` »)
n'est plus vrai. La garde est Organization-scoped depuis TASK-1212 (plafond
`organization_ai_settings.monthly_budget_usd`) et couvre :

- les cinq capabilities canoniques (`loop_summary`, `loop_answer`, `loop_ask`,
  `clarify_help_request`, `loop_knowledge_answer`) et le bac à sable de doctrine ;
- les embeddings (ingestion et requête, TASK-1222) ;
- **TASK-1247 : le chemin hérité `BlogAiService`** (génération, correction,
  méthode sur sélection) — garde AVANT provider avec budget de process
  `blog.*` (`config('ai.blog.economic_guard')`), crédit utilisateur T1229 et
  budget Organization ; ligne `ai_provider_invocations` sur chaque appel
  tenté (succès et échec) avec `credential_source = platform` **déclaré** par
  `ProviderResolver::declareLegacyPlatformCredential()` (la clé est lue dans la
  configuration plateforme, jamais déduite) ; tenant = Organization de
  l'article. Chemin toujours hors Constitution/doctrine et hors BYOK (BLOC E) ;
- **TASK-1248 : le chemin hérité `BlogExplorerController`** (dialogue Explorer
  deep-chat, note d'analyse générée ; alias de routes Organization compris) —
  même patron que T1247, un seul point de passage `callProvider()` : garde
  AVANT provider avec budget de process `blog.explorer_dialogue` /
  `blog.explorer_note` (même `config('ai.blog.economic_guard')`, appliqué par
  process), crédit utilisateur T1229 et budget Organization ; ligne
  `ai_provider_invocations` sur chaque appel tenté (succès et échec,
  `credential_source = platform` déclaré) ; `ai_interactions` sur succès
  seulement ; tenant = Organization de l'article. Un refus est rendu **429
  `{error, code, offers_url}`** — jamais la forme `200 {text}` d'une réponse
  IA. Le throttle `20,1` (fréquence) reste en place à côté, non fusionné.
  Ferme G4 (CRITICAL) du gap analysis T1246 ;
- **TASK-1250 : les trois chemins AUTHENTIFIÉS passant par
  `SupervisionProviderResolver`** (famille C du gap analysis T1246, gaps #13,
  #17, #18 — G5/G9, G7/G8 pour ces chemins). Même patron, posé UNE fois en
  décorateur (`EconomicSupervisionProvider`, obtenu par
  `SupervisionProviderResolver::resolveUnderEconomicAuthority()`) : clé
  plateforme vérifiée et **déclarée** (`declarePlatformCredential()`, absente =
  `ai_not_configured` avant tout appel), garde AVANT provider avec budget de
  process (`config('ai.supervision_resolver.economic_guard')`, appliqué par
  process) et budget de l'Organization de record, une ligne
  `ai_provider_invocations` par appel tenté (succès ET échec, `capability` NULL,
  `credential_source = platform` ou `none` pour ollama). Tenant et crédit,
  chemin par chemin :
  - `ServiceController::formulate()` (#13, `service_offer.master`, `feature =
    service_offer_formulation`) : tenant = Organization courante (celle de
    l'offre), crédit IA du membre appliqué, refus 429 JSON `{error, code,
    offers_url}` ;
  - `AdminMemberAiProfileController::testLlm()` (#17,
    `member_profile.admin_llm_test`) : tenant = Organization du **profil**
    testé (jamais celle de l'administrateur), aucun crédit (banc
    d'administration), usage observé (chat/completions, ollama) → coût
    catalogue ; la trace `admin_ai_interactions` porte désormais tokens,
    `cost_usd` et `cost_unknown` ; refus rendu dans la page avec son code,
    HTTP 429 ;
  - `AdminAiSupervisionController::analyze()` (#18, banc SuperAdmin,
    `feature = admin_ai_supervision_bench`) : tenant de record = Organization
    **plateforme** (`DefaultOrganizationResolver`, `is_default`) — plus jamais
    l'Organization personnelle de l'admin connecté, qui n'est pas le payeur ;
    aucun crédit ; la trace `admin_ai_interactions` suit le même tenant
    (`LoggingSupervisionProvider::forTenant()`). `supervise()` → tokens + coût
    catalogue ; `runScenario()` → usage non exposé par le contrat, donc
    `unknown` (honnête, jamais 0).
  Limite connue, assumée : ces trois chemins n'écrivent pas `ai_interactions`
  (trace produit) ; le budget par process et le crédit utilisateur, qui lisent
  encore cette table, ne voient donc pas leur propre consommation — ils les
  refusent quand d'autres chemins ont épuisé le budget ou le crédit. La
  bascule de l'autorité sur le ledger (G11) comblera cet angle mort.

Reste hors garde à cette date : `MemberProfileAgentResponder` (réponse
automatique de l'agent de profil dans une Boucle agent, #14), la configuration
conversationnelle de l'agent (#16) et le chat visiteur public anonyme (#15) —
voir `GAP-ANALYSIS-ECONOMIQUE-T1246.md`, famille C (TASK dédiées, T1251/T1252).

## Instrumentation des invocations Laravel AI SDK (P1-3)

Cette section décrit l'instrumentation des **embeddings** Dossiers
(`App\Services\Dossiers\DossierChunkEmbeddingService`). Deux call sites :
indexation (`App\Jobs\IndexDossierArticleChunks`, un seul batch SDK pour
tous les chunks d'un article — un seul `invocationId` pour N chunks, le
contrat réel du batch) et recherche sémantique
(`DossierSemanticSearchService`, un seul texte).

### Événements retenus — le sous-ensemble minimal, pas les 28

`GeneratingEmbeddings` (ouverture, fixe l'horodatage de latence) et
`EmbeddingsGenerated` (résultat + usage, écrit la ligne de trace de succès).
Les 26 autres événements installés ne sont **pas** branchés.

`PromptingAgent`/`AgentPrompted`, symétriques côté Agents, ne le sont pas non
plus alors qu'un call site texte existe désormais (`loop_summary`) — et c'est
délibéré. `ai_interactions` est le registre canonique que lit
`AiEconomicGuard` : un listener global écrirait une **seconde** trace pour le
même appel, et le budget compterait cet appel deux fois. L'instrumentation
texte vit donc au call site produit, qui écrit une trace et une seule.

Voir `ARCHITECTURE.md` pour le détail des limites du SDK et la règle P3.

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
