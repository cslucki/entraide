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

### Couverture de la garde (état après TASK-1253)

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
  bascule de l'autorité sur le ledger (G11) comblera cet angle mort ;
- **TASK-1251 : la réponse AUTOMATIQUE de l'agent de profil dans une Boucle
  agent** (famille C, gap #14 — G2 CRITICAL, G10 HIGH) : listener
  `LoopMessageCreated` → job `GenerateAiAgentResponse` →
  `MemberProfileAgentResponder::answerUnderEconomicAuthority()`. La logique
  garde + tentative + ledger du décorateur T1250 est extraite dans
  `SupervisionEconomicAuthority` (le décorateur y délègue ; le responder, qui
  fait ses propres appels HTTP et n'emprunte pas le contrat
  `SupervisionProvider`, l'utilise directement — rien n'est recopié). La
  garde s'exécute **dans le job**, juste avant l'appel provider (pas au
  dispatch) : budget de process `member_profile.loop_agent_reply` (même
  `config('ai.supervision_resolver.economic_guard')`), budget de
  l'Organization de record, crédit T1229 de l'expéditeur ; clé plateforme
  déclarée (`ai_not_configured` sinon). Une ligne `ai_provider_invocations`
  par tentative (succès ET échec, `credential_source = platform` / `none`,
  usage désormais **observé** → coût catalogue, sinon `unknown`). Identité
  économique (posée en T1251, **définitive** depuis TASK-1253) : **tenant =
  Organization du PROFIL** (jamais celle d'un visiteur ; la Boucle doit lui appartenir, sinon
  le job ne fait rien et le dit), **acteur = crédit = l'expéditeur du
  message** (chemin membre, jamais sans crédit ; celui qui interroge l'IA
  consomme son crédit, le propriétaire ne porte pas celui des visiteurs),
  `feature = member_profile_agent_loop_reply`, capability NULL. **Refus en
  asynchrone** : pas de crash, pas de retry, aucun `LoopMessage` (ni faux
  assistant ni repli rule-based), Boucle non touchée, log métier, ligne
  `member_ai_profile_interactions` `status = refused` (réponse NULL, coût
  NULL/NULL) visible par le propriétaire (« Échanges avec mon agent IA »,
  badge). Échec provider : ligne `failed` (coût NULL) puis repli rule-based
  comme avant, `metadata.fallback_after_provider_failure` sur la trace. Même
  limite assumée que T1250 (pas d'`ai_interactions`, compteurs aveugles à
  leur propre consommation jusqu'à G11) ;
- **TASK-1252 : le chat visiteur PUBLIC de l'agent de profil** (famille C,
  gap #15 — **G1 CRITICAL**, la plateforme payait pour un anonyme) : route
  `/profile/{user}/agent-ia` (hors `auth`) → Livewire
  `AiAgentChat::sendMessage()`. Décision produit actée : **aucun appel
  provider anonyme payé en silence par la plateforme**.
  - **Visiteur non authentifié : refus V1 assumé, explicite et humain.**
    Aucune conversation créée, aucun message écrit, aucun appel provider,
    aucune ligne ledger, aucune trace admin. L'agent « répond » par une
    invitation à se connecter / créer un compte (bulle reçue, pas une erreur),
    le composer se verrouille ; encart d'invitation dès le montage. Code
    stable `AiRefusedException::CODE_AUTHENTICATION_REQUIRED`
    (`authentication_required`) — le refus passe par le même chemin que le
    refus budgétaire. État visible du propriétaire : UNE ligne
    `member_ai_profile_interactions` `status = refused`, `visitor_type =
    guest`, provider/modèle NULL (aucun choisi — la vue ne fabrique plus
    « rule_based »), coût NULL/NULL, badge ambre + corps dédié sur « Échanges
    avec mon agent IA » ; bornée à une par session invité et par profil
    (surface d'écriture anonyme strictement plus petite qu'avant). Le code
    de refus ne révèle rien de l'état économique du tenant à un anonyme.
  - **Visiteur authentifié** (membre de l'Organization du profil, compte d'une
    autre Organization, ou le propriétaire qui teste son agent sur
    `/agent-ia/test`) : `answerUnderEconomicAuthority()` (T1251) — clé
    plateforme déclarée, garde AVANT provider (budget de process
    `member_profile.agent_visitor_chat`, même
    `config('ai.supervision_resolver.economic_guard')`, budget de
    l'Organization de record, crédit
    T1229 du visiteur), une ligne `ai_provider_invocations` par tentative
    (succès ET échec, usage observé → coût catalogue, sinon `unknown`).
    Identité économique (posée en T1252, **définitive** depuis TASK-1253) :
    **tenant = Organization du PROFIL visité**, posée explicitement — jamais `current_organization`
    (la requête Livewire ne repasse pas par `ProfileController`), jamais
    l'Organization du visiteur ; **acteur = crédit = le visiteur** ;
    `feature = member_profile_agent_visitor_chat`, capability NULL. La trace
    `admin_ai_interactions` (`logVisitorInteraction()`) porte désormais le
    tenant explicite et l'usage observé (tokens, coût catalogue ou
    `unknown`) ; `costFor()` du responder lit l'usage quand il existe (#16
    sans usage reste `unknown`, inchangé). Refus : aucune réponse de
    substitution, message avec son code (`data-ai-refusal-code`) et lien
    « Voir les offres » si le crédit est épuisé et proposé, la question
    reste dans la conversation sans réponse, ligne
    `member_ai_profile_interactions` `status = refused` (badge propriétaire).
    Échec provider :
    ligne `failed` (coût NULL) puis repli rule-based dit tel quel
    (`fallback_after_provider_failure` sur le message et la trace).
  - `MemberProfileAgentResponder::answerWithDefaultProvider()` (HTTP direct
    sans garde) n'a plus d'appelant et est **supprimé**. La borne
    `MAX_VISITOR_TURNS = 8` reste une borne UX de conversation (effaçable par
    `resetConversation()`) : la borne durable est désormais le crédit du
    visiteur + le budget de l'Organization du profil + le budget de process.
    Même limite assumée que T1250/T1251 (pas d'`ai_interactions`, compteurs
    aveugles à leur propre consommation jusqu'à G11).

**Famille C du gap analysis T1246 : complète** (#13/#17/#18 T1250, #14 T1251,
#15 T1252). Reste hors garde à cette date : la configuration conversationnelle
de l'agent de profil (#16, `MemberAiProfileConversationalSetup` →
`chatWithSetupPrompt()`) — chemin authentifié, borné à `MAX_TURNS = 10` par
session de composant, non public, trace `admin_ai_interactions` coût
`unknown` ; il n'a jamais emprunté `answerWithDefaultProvider()`. À traiter
en TASK dédiée (T1253 l'a laissé hors scope : c'est une fermeture de bypass,
pas une uniformisation) : `SupervisionEconomicAuthority` est prête pour lui
(tenant = Organization du membre, acteur = crédit = le membre).

### Attribution canonique User / Organization / Capability (TASK-1253)

Fiche roadmap « T1249 » du BLOC B, glissée à T1253 : « uniformiser les champs
nécessaires à l'économie sans créer une seconde télémétrie ». Audit des neuf
writers du ledger canonique `ai_provider_invocations` (chemins canoniques #1-#8
et hérités #9-#18 sous autorité) : les champs sont uniformes en valeur ; la
règle qu'ils suivent est écrite une fois pour toutes dans le docblock de
`App\Services\Ai\SupervisionEconomicScope` et tenue par deux invariants de
code. Aucune table, aucune colonne, aucun registre nouveau.

**La règle.**

| Champ du ledger | Règle | Source chemin par chemin |
|---|---|---|
| `organization_id` — tenant de record | l'Organization de l'**objet** sur lequel l'IA travaille : son budget mensuel s'applique, et c'est dans **sa** politique de crédit que le crédit de l'acteur est évalué (compteur tenant × acteur, jamais une lecture dans l'Organization d'origine de l'acteur). Distinct du **payeur de la facture provider** (`credential_source` : `organization` BYOK, `platform` déclaré, `none`), les deux coexistent en base. | Boucle (#1-#4), Dossier (#4b-#7), Organization administrée (#8), **article** (#9-#12), Organization courante = celle de l'offre à créer (#13), **profil** dont l'agent répond (#14, #15, #17), Organization **plateforme** pour le banc SuperAdmin (#18) |
| `user_id` — acteur | l'humain authentifié qui a **déclenché** l'appel ; NULL seulement sans utilisateur (ingestion en job). Reçu explicitement par le writer, jamais lu depuis `auth()` dans le writer (T1253 aligne #17). | demandeur (#1-#4), admin (#8, #17, #18), auteur (#9-#12), membre (#13), **expéditeur** du message (#14), **visiteur** authentifié — le propriétaire qui teste son agent est son propre visiteur (#15) |
| crédit T1229 (`creditUser` de la garde) | **l'acteur lui-même** sur tout chemin membre ; NULL sur les bancs d'administration (#8, #17, #18) et la maintenance (#6/#7). **Invariant** (`SupervisionEconomicScope`) : le crédit est porté par l'acteur ou par personne — le ledger n'a qu'un `user_id`, qui dit donc à la fois qui a agi et qui a payé son crédit. Un « propriétaire qui paierait pour ses visiteurs » exigerait une colonne de plus **et** la levée consciente de l'invariant. | G10 (#14) et #15, tranchés T1251/T1252, **confirmés définitifs** ici |
| `capability` | une capability **canonique** du `CapabilityRegistry` (`loop_summary`, `clarify_help_request`, `loop_knowledge_answer`, `loop_answer`, `loop_ask` — toutes famille A, passage par `PromptRepository::compose()`), ou **NULL** sur un chemin hérité, dit tel quel. **Invariant** (`AiProviderInvocationLedger`) : une valeur non NULL inconnue du registre est refusée (`DomainException`) — aucune étiquette inventée (« `blog_ai_generation` ») ne peut se faire passer pour canonique. Aucune capability canonique n'existe pour Blog, Explorer, agent de profil, offre de service, bancs : leur NULL est la vérité, pas un branchement oublié. | #1-#4, #8 (capability essayée) : registre ; #9-#18 : NULL |
| `feature` | la fonction produit émettrice, renseignée quand elle diffère de la capability (donc toujours sur les chemins hérités, et `ai_doctrine_sandbox` sur le bac à sable). **Fonction produit d'une ligne = `COALESCE(feature, capability)`.** `string(50)` ; la plus longue valeur écrite fait 34 caractères. | `blog_*`, `service_offer_formulation`, `member_ai_profile_llm_test`, `admin_ai_supervision_bench`, `member_profile_agent_loop_reply`, `member_profile_agent_visitor_chat` |
| `process` | l'identifiant stable `AiProcess` de la trace opérationnelle du même chemin (ledger et trace portent le même process). | — |

Couverture de doctrine (`NervousSystemCoverage::INHERITED`, G16) : l'Explorer
d'article (`blog_explorer`, `BlogExplorerController`) est désormais déclaré
hérité ; le chat visiteur et la configuration conversationnelle restent
couverts par la clé `member_profile_agent` (même classe). Ce n'est pas un
second registre : l'inventaire nomme une dette, il ne définit aucune
capability.

**Limite connue, à porter dans G11 (bascule de l'autorité sur le ledger).**
Le compteur de crédit (`OrganizationAiEconomicUsage::userCreditUses()`, via
`OrganizationAiConsumption::baseQuery()`) borne le filtre utilisateur aux
**membres** du tenant (`users.organization_id = tenant`, protection contre
l'extraction cross-tenant par identifiant). Pour un visiteur d'une **autre**
Organization sur le chat de profil (#15, seul chemin où l'acteur peut ne pas
être membre du tenant), ses utilisations ne sont donc jamais comptées : son
crédit, évalué dans la politique du tenant, est inépuisable par construction
— aujourd'hui sans effet (la famille C n'écrit pas `ai_interactions`, le
compteur est aveugle à #15 quel que soit le visiteur), mais structurel. G11
devra compter le ledger par (`organization_id`, `user_id`) sans jointure
`users.organization_id` sur le chemin de **garde** (la ligne vit déjà dans le
tenant : aucune lecture cross-tenant), ou le produit devra borner le chat aux
membres. Décision renvoyée à G11, documentée ici pour ne pas être redécouverte.

Hors scope T1253, suites logiques : #16 (TASK dédiée) ; unification du
**mécanisme** des writers hérités (Blog/Explorer/testLlm portent chacun une
copie garde inline + `recordLedger()` + `tenantOf()`, #13/#14/#15/#18 passent
par `SupervisionEconomicScope` + `SupervisionEconomicAuthority` ; deux blocs de
config `ai.blog.economic_guard` / `ai.supervision_resolver.economic_guard`) — à
faire avec G11 qui touchera ces writers de toute façon ; `DossierChunkEmbeddingService::embed($instance)`
optionnel (G14) ; lectures « par payeur » (relevés V2).

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
