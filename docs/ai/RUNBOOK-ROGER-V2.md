# Runbook Roger V2 — le système nerveux IA de BouclePro, tel qu'il existe

Ce document décrit **l'état réellement livré**, sur `develop` (VERSION
1.180+, après TASK-1237), des neuf mécaniques qui composent le « système
nerveux IA » de BouclePro. Il ne recopie aucune TASK — il en dérive, et
chaque affirmation est vérifiable dans le code cité (`fichier:ligne`). Ce
n'est pas un script de présentation : la version V1
(`TODO/SPECS/_ARCHIVES/RUNBOOK-DEMO-ROGER.md`, gitignorée) reste le
déroulé scénique (scènes, phrases, timing) si une démonstration en direct
est nécessaire. Ce document-ci est la référence technique — ce que fait
le code, pas ce qu'on voudrait qu'il fasse.

**Hiérarchie de vérité** : en cas de contradiction, le code d'abord, puis
les TASK archivées (`TODO/ARCHIVES/`, worktree local), puis ce document.
S'il dérive du code, il doit être corrigé ou retiré — jamais laissé comme
source parallèle.

**Fil conducteur** : chaque capability IA canonique traverse la même
chaîne — `CapabilityRegistry` (ce qu'elle a le droit de lire/écrire) →
Constitution + Doctrine de l'Organization (comment elle doit se comporter)
→ `ContextBuilder` (les sources autorisées) → `AiEconomicGuard` (le budget
et le crédit, vérifiés AVANT tout appel) → provider et credential de
l'Organization (jamais un repli plateforme) → invocation tracée (ledger +
`ai_interactions`, provenance). Les neuf points ci-dessous sont des
manifestations de cette même chaîne, pas neuf systèmes différents.

---

## 1. Intention floue → demande utile

Un membre qui exprime un besoin flou (« j'ai besoin d'aide pour X ») peut
déclencher une clarification IA qui **suggère** une catégorie et une
Boucle pertinentes, avec un formulaire prérempli — elle ne publie jamais
rien elle-même.

**Entrée** : action FAB « Créer ou clarifier une demande d'aide »
(`AiFabContext::ACTION_HELP_REQUEST`) ou bouton équivalent dans
`loop-chat.blade.php:287` / `loops/show.blade.php:579`, tous deux gardés
par `AiConfig::get('clarification_enabled', false)`. Ce gate a une
**double détente** : visibilité UI (`AiFabContext.php:220`) ET contrôle
serveur avant tout appel IA (`LoopController.php:1016` — sinon
`help_request_error`, rien n'est tenté).

**Capability** : `CapabilityRegistry::CLARIFY_HELP_REQUEST =
'clarify_help_request'` (`app/Ai/CapabilityRegistry.php:12`, définition
`:73-88`) — `canWrite: false`, `requiresHumanConfirmation: true`,
`allowedSources: [SOURCE_ORGANIZATION_CATEGORIES, SOURCE_USER_LOOPS]`.
Commentaire explicite (`:76-77`) : « L'humain valide AVANT toute
publication : la capability propose une demande et une Boucle, elle n'en
publie aucune. » Absente de `NervousSystemCoverage::INHERITED` — chemin
**canonique**, pas hérité.

**Service** : `App\Services\Ai\ClarifyUserHelpRequestService` —
`clarifyForLoop()`/`clarifyForOrganization()` → `clarifyInContext()`
(`:119-246`). Borne le contexte via `ContextBuilder::build()` aux
Boucles/catégories réellement offertes (`:151-159`), résout le provider
tenant, applique `AiEconomicGuard`, compose le prompt sous la doctrine
active, appelle `HelpRequestClarifierAgent::prompt()` qui renvoie une
sortie **structurée**. La Boucle/catégorie suggérée est **revalidée
côté serveur** contre les listes exactes fournies au contexte
(`:249-256`, `LoopController.php:1043-1047`) — aucun identifiant halluciné
ne peut survivre. En cas d'échec (config absente, budget dépassé,
exception SDK), repli déterministe sur `FakeAIProvider::analyze()` —
jamais un blocage silencieux.

**Transfert vers l'écran** — `App\Support\Loops\HelpRequestHandoff`
(`app/Support/Loops/HelpRequestHandoff.php`) : jamais de flash Laravel
(le `wire:poll` des pages ChatLoop consomme la requête suivante avant que
l'écran ne la lise). Deux paires store/pull, TTL 15 min, lecture **unique**
(`Cache::forget` immédiat après `Cache::get`) :
- analyse : clé `loops:help-request-handoff:{userId}:{loopId}`, écrite dans
  `analyzeHelpIntention` (`LoopController.php:1051`), lue/invalidée dans
  `LoopController::show()` (`:586`) ;
- brouillon (titre/description/catégorie/Boucle) : clé
  `loops:help-request-draft:{userId}:{organizationId}`, écrite dans
  `prepareHelpRequest` (`:1164`), lue/invalidée dans `RequestController::create()`
  (`:67`).

## 2. Validation humaine

Ce que l'IA n'a **jamais** le droit de décider : la catégorie finale, le
mode (Remote/On site), le budget en points. L'humain les complète sur le
formulaire prérempli, puis publie explicitement.

**Seul point de création** : `RequestController::store()`
(`app/Http/Controllers/RequestController.php:140-165`), route `POST
/requests` (`requests.store`) — reçoit les champs validés et appelle
`ServiceRequest::create([...])` (`:154-165`). Aucun autre point du flux
n'appelle cette création : `analyzeHelpIntention` ne fait que stocker
l'analyse et rediriger ; `prepareHelpRequest` ne fait que stocker un
brouillon et rediriger vers `requests.create`. Le bouton du formulaire est
« publier » (`resources/views/requests/create.blade.php:293`). **Rien
n'est créé avant ce clic explicite.**

## 3. Ask the Folders

Un membre pose une question depuis une Boucle et reçoit une réponse
**sourcée**, exclusivement depuis les Dossiers auxquels il a accès —
lecture seule, aucune écriture, aucune session.

**Route/contrôleur** : `POST /loops/{loop}/knowledge`
(`loops.knowledge.ask`) → `LoopController::knowledge`
(`app/Http/Controllers/LoopController.php:1061-1107`) : vérifie
l'appartenance de la Boucle à l'Organization, l'adhésion active, le gate
`ai.chatloop.enabled`, valide la question (3 à 500 caractères), délègue à
`LoopKnowledgeAnswerService::answer()`. `AiRefusedException` → 422 JSON
`{code, offers_url}` ; succès → JSON `KnowledgeAnswer::toArray()`.

**Recherche sémantique** : `DossierSemanticSearchService::searchAcrossDossiers()`
(`app/Services/Dossiers/DossierSemanticSearchService.php:227-346`) — requête
`pgvector` (exige le driver `pgsql`, sinon exception), Top-N borné à 5
(`DossierRetrievalSource::topK()`, `app/Ai/Context/DossierRetrievalSource.php:196-198`).
**Isolation tenant multi-couches** : jointure `dossiers` filtrée
`organization_id` + non supprimé, `dossier_chunks.organization_id` +
`dossier_id` explicites, Articles exclus si brouillon/futur/supprimé
(`status='published'`, `published_at <= now()`), fichiers exclus si
supprimés.

**Capability** : l'identifiant réel est **`loop_knowledge_answer`**
(`app/Ai/CapabilityRegistry.php:39,90-104`) — à ne pas confondre avec la
clé d'action du FAB `loop_knowledge` (§7), qui est une étiquette de
routage UI, pas l'identifiant de capability. `canWrite: false`,
`allowedSources: [dossier.retrieval]`.

**Gate de disponibilité** : `ai.dossiers.semantic_search.enabled` (défaut
`false`) + `.organization_ids`/`.organization_slugs` (`config/ai.php:42-53`),
vérifié par `DossierSemanticSearchGate::isEnabledFor()`.

**Réponse et citations** : `KnowledgeAnswer::toArray()` —
`{answer, grounded, sources[], consulted[], credit}`, chaque source
`{ref, title, dossier_name, excerpt, url}`. Les références `[S1]`…`[Sn]`
sont attribuées par `DossierRetrievalSource::collect()` et **re-validées**
à la génération (`LoopKnowledgeAnswerService::citedSources()`) — une
référence inventée par le modèle est ignorée. Front : bouton « Consulter
les Dossiers » → événement `bp-open-knowledge` → modal dans
`loops/show.blade.php:451-483`, affiche chaque source avec un lien
`target="_blank"` vers le document.

**Économie** : double garde — `AiEconomicGuard::authorize()` avant la
génération (budget dédié `ai.knowledge.economic_guard.monthly_budget_usd`,
défaut `2.00`), et `AiEconomicGuard::authorizeEmbeddings()` avant
l'embedding de la question. Un seul décompte de crédit utilisateur par
requête.

## 4. Constitution + Doctrine

Toute capability canonique compose ses instructions dans cet ordre
**invariable** : Constitution (plateforme, immuable) → Doctrine de
l'Organization (optionnelle, éditable, versionnée) → instructions de la
capability.

**Constitution** — `app/Ai/Constitution.php:5-23` : `const VERSION = 'v1'`,
`text()` retourne un texte figé dans le code (heredoc). Aucune table,
aucune configuration : **immuable en runtime**, seul un commit peut la
changer.

**Doctrine** — `App\Models\OrganizationAiDoctrine`. `activeFor($organizationId)`
(`:94-101`) retourne l'unique doctrine active (scope + `orderByDesc('version')`).
`activate(Organization, $body, User)` (`:110-166`) : valide (non vide,
≤ 4000 caractères), verrouille la ligne Organization en transaction,
fait passer l'ancienne active en `superseded`, crée `max(version)+1` — un
corps identique à l'actif ne crée pas de nouvelle version. `withdraw()`
retire l'active sans en recréer. Ce sont les **seules primitives
d'écriture** de la doctrine.

**Édition** : `GET/PUT/DELETE /org/{organization}/admin/ai-behavior`
(`organization.admin.ai-behavior`, `routes/web.php:1024-1027`) →
`OrgAdminController::aiBehavior/updateAiDoctrine/withdrawAiDoctrine`.
**Cette page est bornée à l'Organization — il n'existe pas d'équivalent
plateforme** (voir §9 pour ce que le SuperAdmin voit à la place). Un
bac à sable « Tester sans publier » (`sandboxAiDoctrine`, throttle dédié)
fait un vrai appel IA avec la clé de l'Organization, compté dans sa
consommation, sans jamais rien publier ni activer.

**Composition** — `App\Ai\PromptRepository::composeWithDoctrine()`
(`:73-98`) : `[Constitution, bloc doctrine (si actif), "Capability: {id}",
"Instructions capability ({promptKey}): {instructions}"]`, joints par
`"\n\n"`. Le bloc doctrine est délimité par des marqueurs dédiés,
tronqué, et neutralisé contre l'auto-imbrication du délimiteur — traité
comme donnée, jamais interprété comme instruction. **Sans doctrine
active, la composition est byte-identique** à ce qu'elle était avant
TASK-1227 (documenté dans le code).

## 5. Économie — la garde économique

Avant tout appel provider, `AiEconomicGuard::authorize()`
(`app/Support/Ai/AiEconomicGuard.php:66-150`) vérifie, dans cet ordre
**réel** (plus fin que « budget puis crédit ») :

1. budget propre de l'Organization (`monthly_budget_usd`) — refus
   `REASON_ORGANIZATION_BUDGET_REACHED` ;
2. budget mensuel du **process** appelant, passé en paramètre — refus
   `REASON_MONTHLY_BUDGET_REACHED` (distinct du budget Organization
   ci-dessus, plus fin) ;
3. quota d'invocations à coût inconnu ;
4. crédit de l'utilisateur — **en dernier**, volontairement : « quand
   l'Organization elle-même ne peut plus travailler, le message parle de
   l'Organization » (commentaire du code).

**Aucun repli plateforme** : `App\Ai\ProviderResolver::resolve()`
(`app/Ai/ProviderResolver.php:64-125`) lève une exception si l'Organization
n'a pas de credential configuré — jamais de bascule vers une clé
plateforme, même si elle existe en configuration. Cette résolution a lieu
**avant** l'appel au guard.

**Codes de refus** (`App\Support\Ai\AiRefusedException`) :
`CODE_USER_CREDIT_EXHAUSTED`, `CODE_ORGANIZATION_BUDGET_REACHED` (couvre
budget Organization ET budget process), `CODE_NOT_CONFIGURED` (credential
absent), `CODE_UNAVAILABLE` (quota inconnu, etc.). `offersUrl()` ne
renvoie un lien « Voir les offres » que si le code est
`CODE_USER_CREDIT_EXHAUSTED` **et** que le réglage plateforme l'autorise.

**Zéro écriture au refus** : dans les trois sites d'appel vérifiés
(`ChatLoopAiService`, `LoopKnowledgeAnswerService`), le refus est levé
**avant** toute écriture (`AiInteraction::create`, ledger). Commentaire du
code : « Un refus ici n'écrit rien : ni trace, ni ligne de ledger, ni
utilisation décomptée — un appel qui n'est pas parti n'est pas une
utilisation. »

## 6. Crédit

Chaque utilisateur dispose d'un crédit IA mensuel (nombre d'utilisations),
réglé au niveau **plateforme** par défaut, avec un seuil d'alerte, et
**surchargeable** par Organization.

**Réglage plateforme** — `AiUserCreditSettings::updatePlatform()` :
`free_enabled`, `monthly_uses`, `alert_percent` (1-99), `offer_subscription`.
**Override Organization** — `updateOrganization($organization, $mode,
$monthlyUses, $author)`, mode `platform`/`custom`/`unlimited`. Édité via
`PUT /org/{organization}/admin/ai/user-credit`
(`organization.admin.ai.user-credit.update`) →
`OrgAdminController::updateAiUserCredit`. **Règle de repli** : en mode
`custom`, si aucune valeur n'est renseignée, le code retombe
explicitement sur le quota plateforme plutôt que de traiter ça comme
illimité.

**Calcul** — `AiEconomicGuard::userCreditStatus()` : fenêtre = mois
calendaire UTC. Le compteur « used » délègue à
`OrganizationAiEconomicUsage::userCreditUses()`, qui additionne les
générations (`ai_interactions`, hors essais de doctrine en bac à sable)
**et** les recherches documentaires du ledger (`ai_provider_invocations`,
embedding de requête) — jamais les indexations. Objet de valeur
`AiUserCreditStatus` : `used`, `quota()`, `remaining()`, `isExhausted()`,
`isAlerting()` (seuil franchi, pas encore épuisé), `isUnlimited()`,
`renewsAt`.

**Page « Mes usages IA »** (`profile.ai-usage` /
`organization.profile.ai-usage`, `UserAiUsageController`) — **précision
importante** : elle affiche le crédit personnel **et** le coût mesuré
personnel de chaque utilisation (« Coût connu » / historique détaillé par
action) — vérifié en direct sur le banc (86 utilisations, coût mesuré
`$0.008951`, détail par ligne : question, type, provider/modèle, coût,
statut). Ce qu'elle ne montre **jamais**, c'est un chiffre à l'échelle de
l'Organization (budget, consommation globale) — la nuance est entre
« personnel » et « Organization », pas entre « rien » et « coût ».

**Refus au plafond** : le crédit est vérifié en dernier dans
`authorize()` (§5) — zéro écriture, `offersUrl()` conditionné au réglage
plateforme `offer_subscription`.

## 7. FAB contextuel

Le FAB « BouclePro IA » (`App\Support\Ai\AiFabContext`, TASK-1231, étendu
TASK-1237) est un **routeur**, jamais un chatbot ni un service parallèle :
il n'appelle jamais un provider, ne compose aucun prompt. Chaque action
soit déclenche un événement `window` capté par une surface qui existe déjà,
soit suit un lien — les endpoints atteints passent déjà par
`CapabilityRegistry` et `AiEconomicGuard`.

Calculé côté serveur, une lecture par requête (binding `scoped`), injecté
dans `<x-ai-fab />` du **seul** layout membre (jamais guest/admin/org-admin).
Kill-switch : `config('ai.fab.enabled', true)`.

**Actions page Boucle** (gardées par `canContribute` — membre actif, non
désactivé, Boucle inscriptible — **et** `ai.chatloop.enabled`), dans
l'ordre d'affichage :
1. **`loop_ask`** — « Demander à l'IA » (TASK-1237) → événement
   `bp-open-ask-ai`, capté sur le `x-data` **existant** de
   `loop-chat.blade.php` (celui du bouton historique) — ouvre exactement
   le même formulaire `#ai-question` vers la route `loops.ai` (§8), pas
   une variante.
2. **`loop_knowledge`** — « Consulter les Dossiers » → `bp-open-knowledge`
   (§3). *Note* : cette clé d'action est un libellé UI du FAB, distincte
   de l'identifiant de capability réel `loop_knowledge_answer`.
3. **`loop_summary`** — « Résumer la Boucle » → `bp-open-loop-card` /
   `core.ai_summary`, proposée seulement si cette Card est effectivement
   placée pour cet utilisateur dans cette Boucle (même source que la
   barre d'actions).
4. **`help_request`** — « Créer ou clarifier une demande d'aide » (§1) →
   `bp-open-help-request`, proposée seulement si
   `AiConfig::get('clarification_enabled', false)`.

**Page Dossier** : `dossier_search` → `bp-open-dossier-search`, gardée par
`DossierSemanticSearchGate::isEnabledFor()` et la policy de visibilité du
Dossier.

**Ailleurs** : seuls le crédit et le lien « Mes usages IA » sont montrés,
zéro action.

**Crédit et refus** : `AiEconomicGuard::userCreditStatus()` — ambre à
l'alerte ; **au plafond, TOUTES les actions (les quatre) sont remplacées**
par le message de refus + « Voir les offres » (si offert) — rien n'est
appelé, zéro écriture, la même garantie que §5.

## 8. « Demander à l'IA » canonique

`ChatLoopAiService::ask()`/`answer()`, migré dans le système nerveux
canonique par TASK-1233 (auparavant `chatloop_direct_answer`, hérité).

**Route** : `POST /loops/{loop}/ask-ai` (`loops.ai`, throttle 5/min) →
`LoopController::askAi` (`app/Http/Controllers/LoopController.php:1282-1329`)
— `action` = `ask` (avec `question`, requise, ≤ 500 caractères) ou
`answer` (sans question, réponse du facilitateur sur les derniers
échanges). `AiRefusedException` → redirection avec bandeau existant
(`ai_refusal_code` + `ai_offers_url` en session).

**Capabilities** : `CapabilityRegistry::LOOP_ASK` / `LOOP_ANSWER` —
`allowedSources: ['loop.messages']`, **`canWrite: true`** (la réponse est
publiée dans la Boucle), `process` `chatloop.ask`/`chatloop.answer`,
prompts administrables `chatloop_ai_ask`/`chatloop_ai_answer`.

**Contexte** : `ContextBuilder` ne lit que `loop.messages` — la
provenance exacte (ids des messages retenus) est tracée dans
`ai_interactions.metadata.provenance`.

**Publication** : réponse publiée comme `LoopMessage` type `ai` ; pour
`ask`, `reply_to_id` pointe vers le message de la question (lui aussi
publié) ; pour `answer`, `reply_to_id` est `null`. Métadonnées :
`provider`, `model`, `context_message_ids`, `trigger_message_id`,
`ai_interaction_id`.

**Provider** : celui de l'Organization uniquement (`credential_source =
organization` dans le ledger) — même garde qu'ailleurs (§5), aucun repli.

**Depuis TASK-1237**, ce chemin est aussi atteignable depuis le FAB
(§7, action `loop_ask`) — même formulaire, même route, mêmes contrôles ;
invariance prouvée par `TASK1237FabAskAiInvarianceTest` et la spec
Playwright `fab-ask-ai-invariance.spec.js` (chaîne identique, doctrine
appliquée, refus au plafond identique).

## 9. Supervision SuperAdmin sans lecture privée

Un SuperAdmin **plateforme** (`is_admin = true` — ce flag est le seul
gate réel de `AdminMiddleware`, il n'est **pas** conditionné à l'absence
d'Organization) supervise la santé/l'économie de toutes les
Organizations sans lire leur contenu privé.

**`/admin/ai-organizations`** (« Économie IA BouclePro ») →
`AdminAiOrganizationsController::index` — **agrégats purs** : comptes,
budgets, consommé/restant, générations, recherches/indexations RAG,
appels à coût inconnu, échecs — jamais un prompt, une réponse, un
document. La clé API d'une Organization est transformée en booléen
(`ready`) avant même d'atteindre la vue ; elle n'est jamais transmise.
Vérifié en direct sur le banc — la page affiche elle-même : « Jamais un
contenu privé, jamais une clé. »

**`/admin/ia-usage`** (`AdminAiUsageController`) — **nuance importante,
à ne pas généraliser** : la vue détail (`show`/`showAdmin`) affiche **en
clair** le prompt et la réponse d'une interaction. Ce n'est pas une
brèche dans la règle ci-dessus : cette console porte spécifiquement sur
les interactions **Blog IA** (l'assistant d'écriture d'articles), un
contenu **destiné à publication**, pas sur les Boucles/Dossiers privés.
La frontière « aucune lecture privée » s'applique au cockpit RAG/économie
(`ai-organizations`), pas à cette console-là — les deux consoles ont des
classes de confidentialité différentes.

**Comportement IA / couverture** (`NervousSystemCoverage`, X/8 capabilities
couvertes par une doctrine) n'existe qu'à l'échelle d'une Organization
(§4, `admin/ai-behavior`) — il n'y a pas de vue plateforme cross-Organization
listant cette couverture.

---

## Vérifier ces affirmations

Chaque section ci-dessus est adossée à des tests qui échouent si le
comportement dérive :

| Section | Tests de référence |
|---|---|
| 1-2 | tests couvrant `ClarifyUserHelpRequestService`, `RequestController::store` |
| 3 | tests `PgvectorDossierSemanticSearch*`, `LoopKnowledgeAnswerService` |
| 4-5 | `TASK1233LoopDirectAnswerCanonicalTest` (composition, refus, tenant) |
| 6 | tests `AiUserCreditSettings`, `AiEconomicGuard` |
| 7 | `TASK1231AiFabTest`, `TASK1237FabAskAiInvarianceTest` |
| 8 | `TASK1233LoopDirectAnswerCanonicalTest`, `TASK1237FabAskAiInvarianceTest` |
| 9 | tests `AdminAiOrganizationsController`, `AdminAiUsageController` |

Pour l'historique des décisions (pourquoi, pas seulement quoi), voir les
TASK archivées correspondantes dans `TODO/ARCHIVES/` — TASK-1210/1211 (§1-2),
TASK-1213/1225 (§3), TASK-1227/1236 (§4), TASK-1205/1220/1222 (§5),
TASK-1229 (§6), TASK-1231/1237 (§7), TASK-1233 (§8), TASK-1223/1228 (§9).
