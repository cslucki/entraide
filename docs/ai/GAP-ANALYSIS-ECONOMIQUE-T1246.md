# Gap Analysis économique finale — T1246

Audit READ ONLY (aucun code applicatif modifié) de chaque chemin IA actif de
BouclePro, sur `develop` `0af86c1` (VERSION 1.186, après merge T1245 /
`ROGER_READY`). Premier item du BLOC B « Système économique IA fiable » de
`ROADMAP-MASTER-IA-RAG-T1235-T1300`.

Méthode : grep exhaustif des sites d'appel provider, puis lecture de chaque
chemin jusqu'à la trace ; FK des tables de trace lues dans les migrations.
Chaque affirmation cite `fichier:ligne`. Quand une garde n'a pas été trouvée,
c'est écrit tel quel. Aucun bug identifié pendant l'audit n'a été corrigé ici.

---

## 0. Conclusion exécutive

**18 chemins IA actifs**, trois familles :

| Famille | Chemins | Garde avant provider | Ledger canonique | Credential | Payeur |
|---|---|---|---|---|---|
| A. Système nerveux canonique + embeddings | #1–#8 | **oui** (`AiEconomicGuard`) | **oui** (`ai_provider_invocations`) | `organization` / `none`, **prouvé** | **Organization (BYOK)** |
| B. Blog (HTTP legacy) | #9–#12 | **non** (quota par article pour 2 sur 4, rien pour les 2 autres) | **non** | plateforme, **jamais tracée** | **plateforme**, mais la trace impute l'Organization et le user |
| C. `SupervisionProviderResolver` / `MemberProfileAgentResponder` | #13–#18 | **non** | **non** | plateforme, jamais tracée | **plateforme**, dont un chemin **public/anonyme** |

Ce que cela veut dire pour BLOC B :

1. **On peut faire confiance aux compteurs de la famille A** — et seulement à
   elle. C'est l'acquis T1212→T1236, vérifié ligne par ligne.
2. **Les familles B et C débitent les compteurs de la famille A sans y être
   soumises** : leurs lignes `ai_interactions` (B) entrent dans le budget
   mensuel Organization (`AiEconomicGuard::organizationMonthlyKnownCost`) et
   dans le crédit utilisateur T1229 (`OrganizationAiEconomicUsage::userCreditUses`),
   alors qu'elles sont payées par la clé plateforme et jamais refusées.
   **Un budget « atteint » ne prouve pas une dépense BYOK.** (§5.1)
3. **La roadmap nomme B (T1247 BlogAiService, T1248 BlogExplorerController)
   mais pas C.** La famille C contient les deux chemins les plus exposés :
   le chat visiteur public sans auth (#15) et la réponse automatique de
   l'agent de profil à chaque message d'une Boucle agent (#14). C'est l'écart
   principal entre la roadmap et l'état réel. (§7, CRITICAL)
4. **L'autorité économique lit une table volatile.** Guard, relevés et crédit
   lisent `ai_interactions` (`user_id` NOT NULL, ON DELETE CASCADE) ; le ledger
   canonique conçu pour survivre n'est écrit que par 8 chemins sur 18 et
   CASCADE lui-même sur `organizations`. Source de vérité recommandée pour
   T1250 en §5.2 : `ai_provider_invocations` = ledger économique durable,
   bascule d'autorité **après** que B et C y écrivent.
5. **Coût plateforme et prix client n'existent pas dans le système** (aucune
   marge, aucun prix ; le crédit T1229 est en utilisations, pas en monnaie).
   Les colonnes « platform_cost » et « coût client » de la matrice sont donc
   « non modélisé » partout — c'est un constat, pas un oubli de l'audit.

17 gaps classés : **5 CRITICAL, 5 HIGH, 4 MEDIUM, 3 LOW** (§7).

---

## 1. Périmètre et méthode

« Chemin IA actif » = tout code de `app/` qui envoie réellement une requête à
un provider IA (OpenAI, OpenRouter, Ollama), via le Laravel AI SDK
(`$agent->prompt()`, `Embeddings::for()`) ou par HTTP direct.

Sites d'appel réels trouvés (`grep -rn` sur `app/`, commandes en §8) :

| Mécanisme | Sites d'appel |
|---|---|
| SDK texte `->prompt(` | `ChatLoopAiService:329`, `ClarifyUserHelpRequestService:206`, `LoopKnowledgeAnswerService:151`, `OrganizationDoctrineSandbox:274,299` |
| SDK `Embeddings::for()` | `DossierChunkEmbeddingService:43` (seul point d'entrée embeddings) |
| HTTP direct | `BlogAiService:198,220`, `BlogExplorerController:427,448,547,565`, `MemberProfileAgentResponder:165,193,219,247,273`, `AdminMemberAiProfileController:238,267,290`, `OpenAiSupervisionProvider:76,184`, `OpenRouterSupervisionProvider:93,198`, `OllamaSupervisionProvider:60,145` |

Hors périmètre (aucun provider) : `BoundedMemberAgent`, `InlineMemberAgent`
(`provider = rule_based`), `FakeAIProvider` (repli déterministe), `AiFabContext`
(lecture), `AiValidationIndexArtSciLabCommand` (dispatch du pipeline #6),
`UrlPreviewService` (HTTP non-IA).

---

## 2. Infrastructure économique — état vérifié

### 2.1 Les autorités qui existent

| Composant | Fichier | Rôle vérifié |
|---|---|---|
| `AiEconomicGuard::authorize()` | `app/Support/Ai/AiEconomicGuard.php:66` | Garde AVANT provider pour la génération : plafond mensuel Organization (`organization_ai_settings.monthly_budget_usd`), plafond par process, quota d'inconnus par process, crédit mensuel utilisateur (T1229). Lit `ai_interactions` (génération) + `ai_provider_invocations` (embeddings). |
| `AiEconomicGuard::authorizeEmbeddings()` | `:166` | Idem ingestion/query embeddings, lit le ledger. |
| `AiEconomicGuard::organizationMonthlyKnownCost()` | `:292` | **Autorité budget Organization** = `ai_interactions.cost_usd` (cost_unknown=false) + `ai_provider_invocations.provider_cost` (embedding, known). |
| `AiEconomicGuard::finalize()` | `:315` | Coût = rapporté provider si fourni, sinon `AiPricingCatalog`, sinon UNKNOWN. Le SDK v0.7.2 ne rapporte aucun coût : le catalogue est le premier échelon effectif. |
| `AiProviderInvocationLedger` | `app/Services/Ai/AiProviderInvocationLedger.php` | Writer UNIQUE de `ai_provider_invocations` (`recordGeneration`, `recordEmbedding`). Aucune ligne fictive, credential prouvé ou `unknown`, `0 ≠ inconnu`. |
| `ProviderResolver::resolve()` | `app/Ai/ProviderResolver.php:66` | Provider/modèle/credential depuis `organization_ai_settings` (`api_key` cast `encrypted`). **Aucun repli plateforme** (DomainException). Preuve du credential posée dans `Context` (`registerInstance():268`, `credentialSourceFor():295`). |
| `CapabilityRegistry` | `app/Ai/CapabilityRegistry.php` | 5 capabilities canoniques : `loop_summary`, `clarify_help_request`, `loop_knowledge_answer`, `loop_answer`, `loop_ask`. |
| `OrganizationAiConsumption` (T1219) | `app/Services/Ai/OrganizationAiConsumption.php:300` | Autorité de lecture génération = `ai_interactions`, tenant-safe. Documente elle-même (`:71`) : coût plateforme et prix client **n'existent pas**. |
| `OrganizationAiEconomicUsage` (T1228) | `app/Services/Ai/OrganizationAiEconomicUsage.php:305` | `userCreditUses()` = **toutes** les lignes `ai_interactions` du user (hors sandbox) + query embeddings du ledger. |
| `NervousSystemCoverage` | `app/Ai/NervousSystemCoverage.php:36` | Déclare à la main 3 chemins hérités : `member_profile_agent`, `blog_ai`, `service_offer_formulation`. |

### 2.2 Deux couches de credential plateforme (à ne pas confondre)

- `config('ai.providers.{openai|openrouter}.key')` ← `OPENAI_API_KEY` /
  `OPENROUTER_API_KEY` : configuration **SDK**. C'est la clé que le SDK
  utiliserait si un appel partait sur la famille nue (`openai`) au lieu d'une
  instance tenant `org:{id}:{provider}`.
- `config('ai.openai.api_key')`, `config('ai.openrouter.api_key')` (mêmes env)
  : configuration **HTTP legacy**, lue par `SupervisionProviderResolver::providerConfig()`,
  `BlogAiService::callAi()`, `BlogExplorerController`.

Le provider/modèle plateforme est choisi par le SuperAdmin :
`AiConfig::get('default_provider'|'default_model')` (`ai_configs`), écrasant
`config('ai.default_provider')` au boot (`AppServiceProvider:196-203`).

### 2.3 Les cinq tables de trace et leur rétention (FK vérifiées dans les migrations)

| Table | `user_id` | `organization_id` | Contenu | Coût | Qui écrit |
|---|---|---|---|---|---|
| `ai_interactions` | **NOT NULL, ON DELETE CASCADE** (`2026_06_17_225905:13`) | nullable, SET NULL | prompt + réponse complets | `cost_usd`, `cost_unknown` (tri-état) | ChatLoop, Clarify, Knowledge, Sandbox, **Blog, Explorer** |
| `ai_provider_invocations` (ledger canonique T1220) | nullable, **sans FK** (« la ligne économique survit à la suppression du compte », `2026_08_17_160000:37`) | NOT NULL, **CASCADE** | aucun | `provider_cost`, `cost_status`, `cost_source`, `credential_source` | ChatLoop, Clarify, Knowledge, Sandbox, embeddings (listener) |
| `admin_ai_interactions` | nullable, SET NULL | nullable, SET NULL | extraits (`input_excerpt` 500 car., `result_payload` filtré) | `cost_usd`, `cost_unknown` | Bancs supervision, service_offer, profil-agent (setup/visiteur/test admin), embeddings (double écriture) |
| `member_ai_profile_interactions` | `profile_owner_user_id` **CASCADE**, `visitor_user_id` SET NULL | CASCADE | question + réponse | `cost_usd`, `cost_unknown` | `GenerateAiAgentResponse`, `InlineMemberAgent` |
| `ai_credit_setting_changes` | `changed_by` SET NULL | nullable, CASCADE | — | — | `AiUserCreditSettings` |

`UserDataLifecycleRegistry` (`app/Services/UserDataLifecycleRegistry.php:34`)
déclare `ai_interactions.user_id` en politique **ANONYMIZE** alors que la FK
réelle est **CASCADE** ; il ne connaît pas `ai_provider_invocations`. La
suppression d'un User est aujourd'hui un aperçu seulement
(`AdminController::deleteUser:867` rend `preview_only`) : le risque est
latent, pas actif — mais le schéma est ce qui s'exécutera le jour où un
`DELETE users` part (tinker, purge RGPD, future feature).

---

## 3. Matrice A — autorité économique, chemin par chemin

Légende : **oui** / **non** / **partiel** ; « bypass » = n'emprunte pas
`CapabilityRegistry` → `ProviderResolver` → `AiEconomicGuard` → ledger.
« Payeur » = qui paie réellement la facture provider de CET appel.

| # | Chemin (point d'entrée → service → appel) | Guard avant provider | Table(s) de ledger | User attribué | Org attribuée | Capability canonique | Provider / modèle | Credential source | Payeur identifiable |
|---|---|---|---|---|---|---|---|---|---|
| 1 | ChatLoop résumé — Livewire `LoopAiSummaryCard` → `ChatLoopAiService::summarize():140` → SDK `LoopSummaryAgent` | **oui** (`:215` — budget org + budget process + quota unknown + crédit user) | `ai_provider_invocations` + `ai_interactions` (`recordInteraction():428,443`) | oui (`requester`) | oui (Organization de la Boucle, `:448`) | oui `loop_summary` | tenant (`organization_ai_settings.provider/model`) | `organization` (prouvé) / `none` (ollama) | **oui : Organization (BYOK)** |
| 2 | ChatLoop « Demander à l'IA » — `LoopController::askAi():1282` (throttle 5/1 ; FAB T1237) → `ChatLoopAiService::answer():58` / `ask():526` → `generateDirectAnswer():624` | **oui** (`:694`) | idem | oui | oui | oui `loop_answer` / `loop_ask` | tenant | `organization` / `none` | **oui : Organization** |
| 3 | Clarification demande d'aide — `LoopController:1027` (`clarifyForLoop`) et `RequestController::formulate():98` (`clarifyForOrganization`) → `ClarifyUserHelpRequestService::clarifyInContext():119` | **oui** (`:176`) — un refus retombe **silencieusement** sur `FakeAIProvider` (`:187`) | ledger + `ai_interactions` (`:516,531`) | oui | oui | oui `clarify_help_request` | tenant | `organization` / `none` | **oui : Organization** |
| 3b | `ClarifyUserHelpRequestService::analyze():45` — chemin **legacy** (`SupervisionProviderResolver` + `runScenario`) | non | `admin_ai_interactions` via `LoggingSupervisionProvider` (tokens null, `cost_unknown=true`) | via `auth()` | via `current_organization` | bypass | plateforme (`AiConfig`/env) | plateforme, non tracée (`unknown`) | plateforme (implicite). **Code mort en prod** : `AppServiceProvider:104` lie toujours `ClarifyUserHelpRequestService`, `LoopController:1027` teste `instanceof` |
| 4 | Ask the Folders — `LoopController::knowledge():1061` → `LoopKnowledgeAnswerService::answer()` → SDK `LoopKnowledgeAgent` | **oui** (`:94`, avant retrieval ET génération) | ledger + `ai_interactions` | oui | oui | oui `loop_knowledge_answer` | tenant | `organization` / `none` | **oui : Organization** |
| 4b | … son embedding de requête RAG — `DossierRetrievalSource::collect():105` → `DossierSemanticSearchService::searchAcrossDossiers():227` | **oui** (`authorizeEmbeddings`, org seulement — crédit déjà compté en #4, `:37-44`) | ledger (`recordEmbedding`, `embedding_operation=query`, `capability`, `feature`) + `admin_ai_interactions` (`RecordSdkEmbeddingsInvocation::write():242,262`) | oui (`Auth::id()`) | oui (Context de trace) | oui (porte la capability appelante) | famille d'embedding plateforme (`ai.default_for_embeddings`), **clé tenant** (`resolved->instance`) | `organization` / `none` | **oui : Organization** |
| 5 | Recherche sémantique directe d'un Dossier — `DossierSemanticSearchController` (`routes:813`) → `search():85` | **oui** (`:119`, avec crédit user, refus 429 codé) | ledger (query) + `admin_ai_interactions` | oui | oui | non canonique (pas une capability) — mais gardé et ledgerisé | famille embeddings, clé tenant (`resolveEmbeddingInstance`) | `organization` / `none` | **oui : Organization** |
| 6 | Ingestion embeddings Articles — job `IndexDossierArticleChunks` → `DossierArticleIndexer:71,86` | **oui** (`authorizeEmbeddings`, sans user par conception) | ledger (`ingestion`) + `admin_ai_interactions` | **non** (job en file, `Auth::id()` null — voulu : maintenance de la base de connaissances) | oui | non canonique, gardé | famille embeddings, clé tenant | `organization` / `none` | **oui : Organization** |
| 7 | Ingestion embeddings fichiers — job `IndexDossierFileChunks` → `DossierFileIndexer:87,100` | **oui** | idem | non (job) | oui | idem | idem | idem | **oui : Organization** |
| 8 | Bac à sable doctrine (Admin Organization) — `OrgAdminController::sandboxAiDoctrine` (`routes:1035`, throttle dédié) → `OrganizationDoctrineSandbox::run():87` | **oui** (`:143`, budget org + process, hors crédit user par conception) | ledger (`feature=ai_doctrine_sandbox`) + `ai_interactions` | oui (admin) | oui | oui (`loop_knowledge_answer` / `clarify_help_request`) | tenant | `organization` / `none` | **oui : Organization** |
| 9 | Blog génération / correction — `BlogController::handleAi():705` → `BlogAiService::generate():31` / `correct():47` → `callAi():163` HTTP direct | **partiel** : quota **par (user, article)** `BlogAiConfig` (3 générations + 3 corrections par article, `remainingCount():102`), pas de budget monétaire, pas de crédit T1229, pas d'`AiEconomicGuard` | `ai_interactions` uniquement (`:260`), **succès seulement** ; **aucune ligne ledger** | oui | oui (`currentOrganization() ?? user.organization_id`) | **bypass** (`INHERITED['blog_ai']`) | **plateforme** (`AiConfig` → `config('ai.openai'|'ai.openrouter'|'ai.ollama')`) | plateforme, **jamais tracée** | **ambigu** : la plateforme paie ; la trace impute l'Organization et le user (§5.1) |
| 10 | Blog « méthode sur sélection » — `BlogController::aiMethodSelection():662` → `BlogAiService::methodSelection():57` | **non** — aucun quota (ni `checkEnabled`, ni `remainingCount`, ni guard) | `ai_interactions` (feature `blog_method_selection_{méthode}_{locale}`), succès seulement | oui | oui | **bypass** | plateforme | plateforme, non tracée | **ambigu** |
| 11 | Blog Explorer dialogue — `BlogExplorerController::chat():41` → `callAiForDialogue():374` HTTP direct | **non** — seul `throttle:20,1` (`:27`) | `ai_interactions` (`:486`), succès seulement | oui | oui | **bypass** (absent même de `NervousSystemCoverage::INHERITED`) | plateforme | plateforme, non tracée | **ambigu** |
| 12 | Blog Explorer note — `BlogExplorerController::generateNote():79` → `callAiSimple():512` | **non** (throttle 20/1) | `ai_interactions` (`:604`) | oui | oui | **bypass** | plateforme | plateforme, non tracée | **ambigu** |
| 13 | Formulation d'offre de service — `ServiceController::formulate():92` (auth, `routes:256/702`) → `SupervisionProviderResolver::resolve()->runScenario('service_offer_master'):184` | **non** (aucune garde, aucun throttle) | `admin_ai_interactions` via `LoggingSupervisionProvider::runScenario()` : tokens **null**, `cost_unknown=true` | via `auth()` (persistence) | via `current_organization` | **bypass** (`INHERITED['service_offer_formulation']`) | plateforme (`defaultProvider()`) | plateforme, non tracée | **ambigu** : plateforme paie, coût jamais mesuré |
| 14 | Agent de profil — réponse automatique dans une Boucle agent — listener `LoopMessageCreated` (`AppServiceProvider:241`) → job `GenerateAiAgentResponse::handle():41` → `MemberProfileAgentResponder::answerWithDefaultProvider():21` HTTP direct | **non** — chaque message d'un visiteur dans une Boucle agent déclenche un appel | `member_ai_profile_interactions` (`:88`), usage **non observé** → `cost_unknown=true` pour tout provider distant | partiel : `visitor_user_id` + `profile_owner_user_id` — le « consommateur » n'est pas tranché | oui (`loop.organization_id`) | **bypass** (`INHERITED['member_profile_agent']`) | plateforme | plateforme, non tracée | **ambigu** |
| 15 | Agent de profil — chat visiteur **public** — route `/profile/{user}/agent-ia` (`routes:393`, **hors groupe `auth`**, middleware `ai-profiles.enabled` seul ; visiteur anonyme identifié par `visitor_session_id`, `AiAgentChat:166-186`) → `AiAgentChat::sendMessage():75` → `answerWithDefaultProvider(..., 'profile_agent_visitor_chat'):109` | **non** — borne `MAX_VISITOR_TURNS = 8` par conversation, **effacée par `resetConversation():132`** (supprime la conversation et ses messages) | `admin_ai_interactions` (`logVisitorInteraction():478`, `user_id = auth()->id()` → **NULL pour un anonyme**), coût `unknown` | **non** (anonyme possible) | via `current_organization` | **bypass** | plateforme | plateforme, non tracée | **inconnu** : plateforme paie pour un anonyme, sans quota durable |
| 16 | Agent de profil — configuration conversationnelle (membre auth) — Livewire `MemberAiProfileConversationalSetup::start()/send()` → `chatWithSetupPrompt():131` | **non** (`MAX_TURNS = 10` par session de composant) | `admin_ai_interactions` (`logSetupInteraction():448`), coût `unknown` | oui | via `current_organization` | **bypass** | plateforme | plateforme, non tracée | **ambigu** |
| 17 | Test LLM d'un profil par un admin — `AdminMemberAiProfileController::testLlm():118` (`auth`+`admin`) → HTTP direct `:238-290` | **non** | `admin_ai_interactions` (`:161`) — **aucun token, aucun coût, aucun `cost_unknown`** (colonnes non renseignées → `input_tokens=0`, `cost_usd` NULL, `cost_unknown` NULL) | oui (admin) | oui (org du profil) | **bypass** | plateforme (choisi dans le formulaire) | plateforme, non tracée | **ambigu** : plateforme paie, l'org du profil est imputée |
| 18 | Banc de supervision SuperAdmin — `AdminAiSupervisionController::analyze():55` → `supervise()` / `runScenario('clarify_help_request')` | **non** (surface admin plateforme) | `admin_ai_interactions` via `LoggingSupervisionProvider` : `supervise()` porte tokens + coût catalogue ; `runScenario()` porte **null/unknown** | oui (admin) | **org de l'admin connecté** (`resolveOrganizationId()`), pas « plateforme » | bypass (banc) | plateforme | plateforme, non tracée | **partiel** : plateforme paie ; imputé à l'Organization de l'admin |

---

## 4. Matrice B — coûts, traces, erreurs, RAG, rétention

Colonnes :
- **Tokens tracés** : input/output (génération) ou total (embedding) réellement observés et écrits.
- **provider_cost tracé** : coût écrit en base et sa provenance (`catalog_estimated` via `AiPricingCatalog`, `provider_reported` — jamais disponible avec le SDK v0.7.2 —, ou `unknown`).
- **Coût BYOK externe** : facture supportée par le compte provider **de l'Organization** (familles A).
- **platform_cost** : facture supportée par la clé plateforme (familles B, C). **Non modélisé dans le système** : aucune colonne, aucun agrégat, déductible seulement du code.
- **Coût client / facturable** : prix vendu au client. **N'existe pas** (crédit T1229 en utilisations, aucun prix, aucune marge).
- **Corrélation** : `correlation_id` (`AiCorrelation`) écrit sur la trace.
- **Erreur avant vs après consommation** : que se passe-t-il (a) si le refus/l'erreur survient AVANT l'appel provider, (b) si l'appel est parti et échoue. « Consommation fantôme » = une ligne économique sans appel réel ; « consommation non tracée » = un appel réel (potentiellement facturé) sans ligne.
- **Rétention** : ce qu'il advient de la trace de CE chemin à la suppression du User / de l'Organization (cf. §2.3).

| # | Modèle | Tokens tracés | provider_cost tracé | Coût BYOK externe | platform_cost | Coût client | Corrélation | Erreur avant / après consommation | Source RAG | Rétention |
|---|---|---|---|---|---|---|---|---|---|---|
| 1–2 | `organization_ai_settings.model` | oui, in/out (`AiUsage::fromSdkTextTokens`, `0/0` → non observé) | oui, `catalog_estimated` sinon `unknown` (`AiEconomicGuard::finalize`) | oui (Organization) | 0 | non modélisé | oui (`ContexteIa.correlationId`) | avant : refus → **rien écrit** ; après : exception → ligne `failed` dans ledger + `ai_interactions` (cost NULL/NULL) — ni fantôme ni non tracé | non (`loop.messages`) | `ai_interactions` : CASCADE user, SET NULL org ; ledger : survit user, CASCADE org |
| 3 | idem | oui | oui | oui | 0 | non modélisé | oui | avant : refus → rien écrit **et repli déterministe silencieux** ; après : ligne `failed` + repli | non (`user.loops`, `organization.categories`) | idem |
| 3b | `AiConfig`/env | non (null) | non (`unknown`) | 0 | oui, non mesuré | non modélisé | oui (persistence) | après : exception → `LoggingSupervisionProvider` ne persiste rien (persist après retour) → **appel échoué non tracé** | non | `admin_ai_interactions` : SET NULL / SET NULL |
| 4 | tenant | oui | oui | oui | 0 | non modélisé | oui | avant : refus → rien ; après : ligne `failed` | **oui** `dossier.retrieval` (chunks pgvector des Dossiers accessibles au user, top-K, `max_distance`) | idem 1–2 |
| 4b / 5 | famille embeddings (`ai.providers.*.models.embeddings.default`) | total seulement (SDK), in/out NULL | oui, `catalog_estimated` sinon `unknown` | oui | 0 | non modélisé | oui | avant : refus → rien ; après : `recordFailure()` → ligne `failed` ledger + admin | oui | ledger : survit user, CASCADE org ; `admin_ai_interactions` : SET NULL |
| 6–7 | idem | total | oui | oui | 0 | non modélisé | oui (job) | avant : refus → rien, chunks obsolètes supprimés ; après : ligne `failed`, chunks supprimés, exception relancée (retry job = nouvelle ligne, jamais écrasée ; `IndexDossierArticleChunks::$tries = 3`) | — (ingestion) | idem |
| 8 | tenant | oui | oui | oui | 0 | non modélisé | oui (+ `ledgerEntries()` rendus à l'écran) | avant : refus codé (`DoctrineSandboxResult` refused, `ledgered=false`) ; après : ligne `failed` | selon capability (knowledge → oui) | idem 1–2 |
| 9–10 | `AiConfig::get('default_model')` ou `config('ai.default_model')` ou modèle par provider | oui in/out (`fromChatCompletions` / `fromOllamaGenerate`) | oui, `catalog_estimated` dans `ai_interactions.cost_usd` (aucun ledger) | 0 | **oui, non modélisé** | non modélisé | oui | avant : `enabled=false` → 403, quota → 429, rien écrit ; **après : HTTP non-2xx ou `ConnectionException` → `RuntimeException` AVANT `AiInteraction::create` → appel parti (timeout après génération possible) non tracé et non décompté du quota** | non | `ai_interactions` : CASCADE user |
| 11–12 | idem | oui | oui, `catalog_estimated` | 0 | oui, non modélisé | non modélisé | oui | avant : throttle 429 ; après : idem 9 (**échec non tracé**) | non | idem |
| 13 | `providerConfig()['model']` | **non** (null) | **non** (`cost_unknown=true`) | 0 | oui, non mesurable | non modélisé | oui | après : `SupervisionException` → non persisté (**échec non tracé**) | non | `admin_ai_interactions` : SET NULL |
| 14 | `defaultProvider()` + `providerConfig()['model']` | **non** (`AiUsage::notObserved()`) | non (`unknown` pour distant ; `free`/0 connu pour ollama/rule_based) | 0 | oui, non mesurable | non modélisé | oui (liée au job) | après : exception dans le job → **aucune trace** ; retry worker = nouvel appel provider non tracé | non | `member_ai_profile_interactions` : CASCADE owner, SET NULL visiteur, CASCADE org |
| 15 | idem | non | non (`unknown`) | 0 | oui, non mesurable | non modélisé | oui | après : `catch \Throwable` → erreur UI, **aucune trace** | non | `admin_ai_interactions` : SET NULL (user déjà NULL si anonyme) |
| 16 | choisi au mount | non | non | 0 | oui, non mesurable | non modélisé | oui | après : catch → aucune trace | non | idem |
| 17 | choisi dans le formulaire | **non** (colonnes absentes → 0) | **non** (`cost_usd` NULL **et** `cost_unknown` NULL : « non évalué ») | 0 | oui, non mesurable | non modélisé | oui | après : exception capturée → ligne `status=error` (bien) sans coût | non | idem |
| 18 | choisi dans le formulaire | `supervise()` : oui ; `runScenario()` : non | `supervise()` : oui catalogue ; `runScenario()` : `unknown` | 0 | oui | non modélisé | oui | après : `SupervisionException` → non persisté | non | idem |

---

## 5. Findings transverses

### 5.1 Les chemins plateforme consomment les compteurs de l'Organization sans être bloqués par eux

Vérifié :

- `AiEconomicGuard::organizationMonthlyKnownCost()` (`:292`) somme **toutes**
  les lignes `ai_interactions` de l'Organization à coût connu — y compris
  `blog_generate`, `blog_correct`, `blog_explorer*`, `blog_method_selection_*`
  (payées par la clé plateforme).
- `OrganizationAiEconomicUsage::userCreditUses()` (`:305`) compte **toutes**
  les lignes `ai_interactions` du user (hors sandbox) — les usages Blog
  décomptent donc le **crédit IA T1229** de l'utilisateur.
- Aucun de ces chemins n'appelle `AiEconomicGuard` : ils ne sont jamais
  refusés, mais ils peuvent faire refuser les capabilities canoniques BYOK de
  la même Organization (budget « atteint » par des dépenses qu'elle n'a pas
  payées) et épuiser le crédit d'un membre.

Conséquence produit : le chiffre montré à l'Admin Organization mélange deux
payeurs. C'est le cœur de T1249 (attribution) et la raison pour laquelle
T1247/T1248 ne peuvent pas se contenter d'« ajouter la garde » sans dire
**qui paie** (Organization via BYOK ou plateforme).

### 5.2 Le ledger canonique n'est pas encore l'autorité, et l'autorité actuelle est volatile

- L'autorité génération (guard + relevés + crédit) lit **`ai_interactions`**.
- `ai_interactions.user_id` est **NOT NULL + ON DELETE CASCADE**. Supprimer
  un User efface ses lignes : le budget mensuel « connu » de l'Organization
  **baisse**, les relevés T1228 changent rétroactivement, l'historique
  économique du tenant devient faux.
- `ai_provider_invocations` (sans FK user, `user_id` conservé comme uuid
  orphelin) est conçu pour survivre — mais **CASCADE sur `organizations`** :
  la suppression d'une Organization efface son histoire économique alors que
  `ai_interactions.organization_id` passe à NULL (ligne orpheline conservée).
  Les deux tables ont des politiques inverses selon l'axe (user vs org).
- `admin_ai_interactions` (SET NULL des deux côtés) survit à tout, mais son
  coût est `unknown` sur la plupart des chemins plateforme et le contenu y
  est tronqué : ce n'est pas un ledger économique.
- Les chemins #9–#18 **n'écrivent aucune ligne** dans `ai_provider_invocations`
  : le ledger ne couvre que #1–#8. Basculer l'autorité sur le ledger sans
  d'abord y faire écrire #9–#12 **ferait disparaître** leur consommation du
  budget Organization (aujourd'hui comptée via `ai_interactions`).

**Source de vérité recommandée (T1250)** :

1. `ai_provider_invocations` = **seul ledger économique durable** : une ligne
   par appel provider réellement tenté (succès et échec), sans contenu, sans
   FK `users`, et une politique de rétention `organizations` à trancher
   (SET NULL ou archivage explicite : un ledger qui perd les lignes d'un
   tenant supprimé perd la facture plateforme correspondante).
2. `ai_interactions` = trace **produit** (contenu, provenance, doctrine,
   sources) : rétention alignée sur la donnée personnelle (CASCADE ou
   ANONYMIZE comme le déclare déjà `UserDataLifecycleRegistry` — mais la
   déclaration et la FK doivent cesser de se contredire).
3. `admin_ai_interactions` = trace **opérationnelle** des bancs et scénarios
   plateforme ; la double écriture embeddings
   (`RecordSdkEmbeddingsInvocation::write():262`) devient redondante une fois
   le ledger autorité.
4. Bascule d'autorité (guard, relevés, crédit) vers le ledger **après** que
   #9–#18 y écrivent (T1247/T1248 + une TASK pour #13–#18) et qu'un test de
   couverture prouve « chaque site d'appel provider = une ligne ledger ».

### 5.3 Credential plateforme : jamais prouvé, jamais tracé

`ProviderResolver` ne pose la preuve que pour `organization` et `none`
(`registerInstance():268-283`). Aucune primitive plateforme n'existe : un
appel sur la famille nue serait tracé `credential_source = unknown`. Les
chemins #9–#18 n'écrivent pas de ledger, donc `credential_source = platform`
n'apparaît **jamais** en base. Le payeur « plateforme » n'est déductible que
par lecture du code. Candidat T1249 : une primitive
`ProviderResolver::resolvePlatform*()` qui déclare `platform` elle-même.

### 5.4 Repli latent vers la clé plateforme dans le service d'embeddings

`DossierChunkEmbeddingService::embed($texts, ?string $instance = null)` :
`->generate($instance ?? $provider, $model)` (`:45`). Tous les appelants
actuels passent une instance tenant (vérifié : #4b, #5, #6, #7). Un futur
appelant qui oublie l'argument partirait **silencieusement** sur la famille
nue = clé plateforme, tracé `credential_source = unknown`. Doctrine P4
« aucun fallback silencieux » non tenue par la primitive elle-même.

### 5.5 Échecs non tracés sur les chemins HTTP legacy et plateforme

#9–#13, #14–#16, #18 : l'exception est levée avant la trace. Un appel parti
et échoué (donc potentiellement facturé côté provider) n'existe nulle part ;
sur #9 le quota par article n'est pas décompté non plus. Le ledger canonique
trace succès **et** échec (#1–#8).

### 5.6 Documentation en retard sur le code

`docs/ai/OBSERVABILITE-COUTS.md` §« Limite actuelle » affirme encore que la
garde ne couvre que `loop_summary` ; le code couvre 5 capabilities + embeddings.
`docs/ai/ARCHITECTURE.md` §« Les trois tables de trace » ne connaît pas
`ai_provider_invocations`. À rafraîchir dans la TASK qui bascule l'autorité
(T1250), pas avant.

### 5.7 `NervousSystemCoverage::INHERITED` est incomplet

Il déclare `member_profile_agent`, `blog_ai`, `service_offer_formulation`.
Il ne nomme ni `BlogExplorerController` (#11–#12 ; la cible déclarée est
`App\Services\BlogAiService`), ni le chat visiteur public (#15), ni la
configuration conversationnelle (#16). L'Admin Organization n'est pas informé
que l'Explorer et l'agent visiteur échappent à sa doctrine **et** à son budget.

---

## 6. Points vérifiés conformes (pour ne pas les ré-auditer)

- Aucun repli plateforme dans `ProviderResolver::resolve()` ni
  `resolveEmbeddingInstance()` (DomainException / `null` explicites).
- Un refus de garde n'écrit rien (ni trace, ni ledger, ni crédit) sur #1–#8 :
  `authorize()` précède tout `->prompt()` / `embed()`, `recordInteraction()`
  n'est atteint qu'après.
- Le ledger ne déduit jamais le credential (`credentialSourceFor()` lit le
  registre `Context`, sinon `unknown`).
- `0 ≠ inconnu` respecté dans le ledger (`AiUsage::notObserved()`,
  `cost_status unknown`), et par `AiPricingCatalog::cost()` sur #9–#14.
- Tenant : #1–#8 posent `organization_id` depuis la Boucle / le Dossier / le
  Context de trace, pas depuis la requête ; `OrganizationAiConsumption` borne
  le filtre user au tenant. #9–#12 : `currentOrganization()` est vérifié égal
  à `post->organization_id` avant l'appel (`BlogExplorerController:44`,
  `BlogController::aiMethodSelection:677`). Aucune fuite cross-tenant trouvée.
- Le crédit utilisateur n'est compté qu'une fois sur le chemin RAG
  (`DossierSemanticSearchService::queryEmbeddingAllowed()` sans user).
- Aucun secret dans les traces : `admin_ai_interactions` filtre les clés
  interdites ; le ledger n'a ni prompt ni contenu ; `organization_ai_settings.api_key`
  est `encrypted` et `hidden`.

---

## 7. Gaps classés — CRITICAL / HIGH / MEDIUM / LOW

Critère : CRITICAL = argent réel de la plateforme peut partir sans garde ni
identité stable, ou payeur indistinguable dans un compteur qui bloque ; HIGH
= comptage ou attribution faux/absents sur un chemin utilisateur réel ;
MEDIUM = durabilité/cohérence du ledger ou dette latente ; LOW = doc,
couverture déclarative, UX de refus.

### CRITICAL

| # | Gap | Chemin(s) | Justification | Cible |
|---|---|---|---|---|
| G1 | Chat visiteur **public/anonyme** de l'agent de profil : appel provider plateforme sans `auth`, `user_id` NULL, cap 8 tours effacé par `resetConversation()`, coût jamais mesuré. | #15 | Facturation plateforme silencieuse déclenchable par n'importe qui, sans quota durable ni identité. | TASK dédiée (décision produit : fermer, borner côté serveur, ou passer sous crédit/garde). Non nommée par la roadmap. |
| G2 | Réponse automatique de l'agent de profil à **chaque message** d'une Boucle agent : aucune garde, usage non observé, échec/retry non tracé. | #14 | Volume non borné par utilisateur ni par Organization ; coût inconnu par construction. | TASK dédiée ; a minima usage observé + garde Organization. |
| G3 | `BlogAiService::methodSelection()` : **aucun** quota ni garde (contrairement à generate/correct). | #10 | Chemin utilisateur ordinaire, plateforme paie, illimité. | T1247 |
| G4 | `BlogExplorerController` chat/note : throttle seul (20/min/user), aucune garde monétaire, aucun crédit. | #11–#12 | 20 appels/min/user sur la clé plateforme, MAX_EXCHANGES = 50 messages de contexte par appel. | T1248 |
| G6 | Les chemins plateforme (#9–#18) débitent le budget Organization et le crédit user **sans y être soumis** ; le payeur n'est pas distinguable en base (aucun `credential_source = platform`). | #9–#18, §5.1, §5.3 | Un compteur qui bloque (budget, crédit) est alimenté par un payeur qu'il ne représente pas : « payeur ambigu » au sens du HARD GATE roadmap. | T1249 (primitive plateforme + lectures par payeur), prérequis de T1250 |

### HIGH

| # | Gap | Chemin(s) | Justification | Cible |
|---|---|---|---|---|
| G5 | `ServiceController::formulate` (`service_offer_master`) : aucune garde, aucun throttle, tokens null, coût inconnu. | #13 | Chemin membre auth, plateforme paie, jamais mesuré. | TASK dédiée ou extension T1247 (« autorité économique des chemins SupervisionProviderResolver »). |
| G7 | Ledger canonique écrit par 8 chemins sur 18. | #9–#18 | Sans ligne ledger, T1250 ne peut pas devenir autorité sans perdre du budget consommé. | T1247/T1248 (Blog) + TASK #13–#18 ; T1250 prouve « un site d'appel = une ligne ». |
| G8 | Échecs provider non tracés sur les chemins HTTP legacy/plateforme ; sur #9 le quota par article n'est pas décompté sur échec. | #9–#16, #18, §5.5 | Un appel parti n'existe pas en base : consommation non tracée. | T1247/T1248 + TASK #13–#18 |
| G9 | `AdminMemberAiProfileController::testLlm` : ni tokens, ni coût, ni `cost_unknown` (trace économiquement vide, imputée à l'org du profil). | #17 | Coût plateforme imputé à un tenant, non mesurable. | TASK #13–#18 |
| G10 | Consommateur ambigu sur `member_ai_profile_interactions` (owner vs visiteur) : qui porte le crédit/le quota ? | #14 | Sans décision, aucune garde par utilisateur n'est définissable. | T1249 (décision) |

### MEDIUM

| # | Gap | Justification | Cible |
|---|---|---|---|
| G11 | Autorité génération sur `ai_interactions` (CASCADE user) : budget/relevés/crédit changent à la suppression d'un user (§5.2). Bascule vers `ai_provider_invocations` **après** G7. | Risque latent (suppression preview-only), mais structurel. | T1250 |
| G12 | `ai_provider_invocations` CASCADE `organizations` vs `ai_interactions` SET NULL : le ledger perd l'histoire d'un tenant supprimé ; politiques inverses. Décision de rétention à prendre. | Un ledger économique durable ne peut pas dépendre de la vie du tenant. | T1250 (+ décision Cyril) |
| G13 | `UserDataLifecycleRegistry` déclare `ai_interactions` ANONYMIZE alors que la FK est CASCADE ; `ai_provider_invocations` absent du registre. | Le registre est l'autorité déclarative de suppression : il ment sur l'IA. | T1250 ou TASK lifecycle |
| G14 | Repli latent clé plateforme dans `DossierChunkEmbeddingService::embed()` (`$instance` optionnel, `:45`). | Aucun appelant fautif aujourd'hui ; primitive non-conforme P4. | T1249/T1250 (rendre `$instance` obligatoire) |

### LOW

| # | Gap | Justification | Cible |
|---|---|---|---|
| G15 | Refus économique de Clarify invisible (repli déterministe silencieux, `:187`). | Consommation fantôme nulle (conforme) ; membre et Admin ignorent qu'un budget est atteint. | T1251/T1254 (relevés/alertes) plutôt que T1247 |
| G16 | `NervousSystemCoverage::INHERITED` incomplet (§5.7) ; docs OBSERVABILITE/ARCHITECTURE en retard (§5.6). | Information Admin Organization incomplète ; doc. | T1249 (coverage) / T1250 (docs) |
| G17 | Code mort économiquement dangereux : `ClarifyUserHelpRequestService::analyze()` (chemin plateforme legacy) reste appelable ; `AiProcess`/`SupervisionProviderResolver` le supportent encore. | Non atteignable en prod aujourd'hui (binding + `instanceof`), mais un refactor le réveille. | T1247+ (suppression) |

**Hors gap (décisions déjà prises, rappelées pour T1247+)** : l'ingestion
n'a pas de user (maintenance Organization) ; le sandbox doctrine est hors
crédit user mais dans le budget ; la double écriture ledger + `ai_interactions`
n'est pas un double comptage tant qu'aucune lecture ne somme les deux ;
« platform_cost » et « prix client » n'existent pas et ne sont pas inventés.

---

## 8. Annexe — commandes de recherche utilisées

```
grep -rn --include=*.php -E "Http::(withToken|withHeaders|baseUrl|post|get|timeout)|->prompt\(|Embeddings::|->embed\(" app
grep -rn --include=*.php -l "Laravel\\Ai|OpenAI|openai|openrouter|anthropic|ollama" app config routes
grep -rn "AdminAiInteraction::create|AiInteraction::create|MemberAiProfileInteraction::create|recordGeneration\(|recordEmbedding\(|->persist\(" app
grep -rn "SupervisionProviderResolver|->supervise\(|->runScenario\(" app routes
grep -rn "MemberProfileAgentResponder|answerWithDefaultProvider|chatWithSetupPrompt" app routes
grep -rn "ClarifyUserHelpRequestService|clarifyForLoop|clarifyForOrganization|ChatLoopAiService|DossierSemanticSearchService|DossierChunkEmbeddingService" app routes
grep -n "foreign|cascade|nullOnDelete|constrained" database/migrations/*ai* database/migrations/*credit*
grep -n "^Route::middleware|^Route::group|^Route::prefix" routes/web.php
```
