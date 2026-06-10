---
task_id: TASK-233
title: AI Supervision Scenarios & Member Profile Agent Blueprint

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-233-ai-supervision-scenarios-member-profile-agent-blueprint

priority: MEDIUM

created_at: 2026-06-10 19:50:13 Europe/Paris
updated_at: 2026-06-10 19:56:00 Europe/Paris

labels:
  - blueprint
  - architecture
  - ai
  - supervision
  - scenarios

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-10 20:10:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Blueprint / audit de l'existant du module `/admin/ai-supervision` pour préparer son évolution en laboratoire de scénarios IA BouclePro multi-scénarios :

1. `supervision_content` — supervision de contenu (existant, à refactorer)
2. `clarify_help_request` — clarification / désambiguïsation d'une demande d'entraide vague → produit un titre, une demande clarifiée, un type d'aide, une catégorie suggérée, une loop suggérée, des questions de clarification, un brouillon publiable, un niveau de confiance, et un flag de révision humaine
3. `generate_member_ai_profile` — génération d'une fiche IA bornée à **une prestation validée par le membre** (pas une analyse globale du membre ; pour la bêta, l'IA résume uniquement ce que le membre a validé comme offre de service)
4. `answer_member_profile_question` — réponse bornée depuis **cette fiche/prestation validée** uniquement

Aucun commit fonctionnel lourd : c'est un blueprint.

Contraintes :
- Ne pas coder de feature publique.
- Ne pas modifier ChatLoop.
- Ne pas modifier l'annuaire public.
- Ne pas créer de migration DB.
- Ne pas créer de chatbot généraliste.
- Ne pas casser OpenAI.
- Ne pas supposer que OpenRouter existe.
- IA provider-agnostic, prompt-driven, organization-scoped.
- Organization = Tenant. Loop ≠ Tenant.

---

# Audit de l'existant (fichiers lus)

## Fichier : `routes/web.php` (lignes 268-269)
- Routes admin existantes : `GET /admin/ai-supervision` → `index`, `POST /admin/ai-supervision` → `analyze`.
- Route nommée `admin.ai-supervision` et `admin.ai-supervision.analyze`.
- Route soeur : `GET /admin/ia-design-lab` + `POST /admin/ia-design-lab` (design lab avec FakeAIProvider).
- Les deux routes sont indépendantes.

## Fichier : `config/ai.php`
- Section `openai` : api_key, base_url, model, max_output_tokens, timeout, pricing.
- Section `supervision.enabled` : booléen.
- Section `supervision.taxonomy` : categories + skills (config statique avec fallback DB).
- **Bottleneck** : la config est orientée supervision_content uniquement. Pas de structure pour scénarios multiples.

## Fichier : `app/Http/Controllers/Admin/AdminAiSupervisionController.php`
- Injection `SupervisionProvider` dans le constructeur.
- `AVAILABLE_MODELS` constant — liste OpenAI uniquement (gpt-4o-mini, gpt-4o, gpt-4.1-mini, gpt-4.1-nano, o4-mini).
- `index()` : passe models, model, enabled à la vue.
- `analyze(Request)` : valide content + model, appelle `provider->supervise()`, catch `SupervisionException`.
- **OpenAI-specific** : la liste des modèles est verrouillée OpenAI + Responses API.
- **Bloquant multi-scénarios** : `SupervisionProvider` n'a qu'une seule méthode `supervise()`. Impossible d'ajouter `clarify` ou `generateProfile` sans casser l'interface.

## Fichier : `app/Services/Ai/Contracts/SupervisionProvider.php`
- Interface simple : `supervise(string $content, ?string $model = null): AiSupervisionResult`.
- **Réutilisable** : le pattern interface + implémentation unique est sain.
- **Bloquant** : une seule méthode. Besoin d'une interface multi-scénario ou d'un routeur de scénario.

## Fichier : `app/Services/Ai/Providers/OpenAiSupervisionProvider.php`
- Implémente `SupervisionProvider`.
- **Points clés** :
  - Appelle OpenAI Responses API (`/v1/responses`).
  - `json_schema` strict avec `additionalProperties: false`.
  - System prompt construit depuis `BASE_SYSTEM_PROMPT` + taxonomie chargée depuis DB.
  - Retry logic (3 tentatives, backoff exponentiel sur 429).
  - Pricing calculé depuis config.
- **OpenAI-specific** :
  - Le payload Responses API (`input[].role`, `text.format.type: json_schema`).
  - `extractOutputText()` parse la structure Responses API.
  - Le `model` est passé tel quel à OpenAI.
- **Réutilisable** :
  - `loadTaxonomyFromDb()` — peut être mutualisé.
  - `jsonSchema()` — pattern adaptable par scénario.
  - `estimateCost()` — util générique.
  - `buildPayload()` — template pattern.
  - Gestion d'erreur + retry.

## Fichier : `app/Services/Ai/DTO/AiSupervisionResult.php`
- DTO final avec 15 propriétés : summary, riskLevel, category, skills, unmatchedTerms, needsHumanCategoryReview, categoryReviewReason, recommendations, moderationFlag, notes, inputTokens, outputTokens, model, estimatedCostUsd, latencyMs.
- Méthodes : `isHighRisk()`, `totalTokens()`, `toArray()`.
- **OpenAI-specific** : les champs sont calqués sur le json_schema supervision_content.
- **Réutilisable** : le pattern DTO + toArray() + telemetry (tokens, cost, latency) est à conserver.
- **Bloquant** : trop spécialisé supervision. Besoin d'une enveloppe normalisée pour les autres scénarios.

## Fichier : `app/Services/Ai/Exceptions/SupervisionException.php`
- Simple extension de RuntimeException.
- **Réutilisable tel quel** : peut servir de base pour toutes les exceptions IA.

## Fichier : `resources/views/admin/ai-supervision/index.blade.php`
- Template unique : formulaire (textarea + select model) + résultat structuré.
- Affiche : résumé, risque, catégorie, compétences, unmatched terms, recommandations, modération, notes, métriques.
- **OpenAI-specific** : le select model liste des modèles OpenAI.
- **Réutilisable** : le pattern carte de résultat (header risque + body sections + footer telemetry) est bon.

## Fichier : `app/Providers/AppServiceProvider.php` (lignes 44-56)
- Binding SupervisionProvider → OpenAiSupervisionProvider avec config complète.
- **Point d'extension** : c'est ici qu'on ajoutera la factory multi-provider.

## Fichier : `tests/Feature/Admin/AdminAiSupervisionTest.php`
- 10 tests : accès, analyse, payload Responses API, validation, fuite de clé, header Authorization, échec OpenAI, disabled, mapping catégorie, taxonomy override.
- Utilise `Http::fake()` avec structure Responses API.
- **Réutilisable** : pattern de fake HttpResponse pour tests IA.
- **À étendre** : chaque nouveau scénario aura son test dédié.

## Fichiers additionnels lus
- **`AdminIaDesignLabController`** : pattern injection `FakeAIProvider`. Utilise un FakeAIProvider avec scénarios codés en dur. Préfigure l'architecture scénarios.
- **`AssistedInteractionLabResult`** : DTO pour l'IA Design Lab. Champs : intent, confidence, title, need, context, expectedHelpType, deadline, suggestedLoop, tone, messageDraft, fallback, humanValidation, safety, scenario.
- **`FakeAIProvider`** : 7 scénarios + fallback. Match par trigger words. Pattern de scénario riche.
- **`AiProvider`** : interface `analyze(string $phrase): AssistedInteractionLabResult` pour l'IA Design Lab.

## Synthèse audit

| Dimension | Réutilisable | OpenAI-specific | Bloquant multi-scénarios | Intact |
|---|---|---|---|---|
| SupervisionProvider interface | Pattern interface | Méthode unique `supervise` | Oui, une seule méthode | À refactorer |
| OpenAiSupervisionProvider | Retry, pricing, taxonomie, buildPayload | Responses API, json_schema, model list | Payload verrouillé supervision | À scinder |
| AiSupervisionResult DTO | Telemetry (tokens, cost, latency) | Champs supervision | Trop spécialisé | À envelopper |
| SupervisionException | Oui | Non | Non | Intact |
| config/ai.php | Structure openai + supervision | Oui | Pas de section scenarios | À étendre |
| Vue index.blade.php | Pattern carte résultat | Select model OpenAI | Template unique | Reste pour supervision |
| Tests | Pattern Http::fake | Structure Responses API | Un seul scénario testé | À étendre |
| FakeAIProvider | Pattern scénario riche | Non | Non | Référence architecture |
| AdminIaDesignLabController | Pattern design lab | Non | Non | Référence |

---

# Architecture Cible

## Principe

```text
Admin Controller
  └─ ScenarioRouter (délègue par scenario_key)
       ├─ supervision_content    → OpenAiSupervisionProvider (refactoré)
       ├─ clarify_help_request   → ClarifyHelpRequestProvider
       ├─ generate_member_ai_profile → GenerateMemberProfileProvider
       └─ answer_member_profile_question → AnswerProfileQuestionProvider
```

Chaque scénario = une implémentation concrète qui respecte une interface commune.

## AiScenarioDefinition

```php
interface AiScenarioDefinition
{
    public function scenarioKey(): string;
    public function buildSystemPrompt(): string;
    public function jsonSchema(): array;
    public function parseResponse(array $parsed, array $rawBody): AiScenarioResult;
}
```

## PromptBuilder par scénario

Chaque scénario définit son system prompt DANS SA CLASSE — pas de prompt stocké en BDD ni dans config.

Règles :
- Le prompt est une constante ou une méthode privée dans la classe du scénario.
- Peut charger la taxonomie (via trait ou provider mutualisé).
- Ne contient pas de données utilisateur (celles-ci sont dans le message user).
- En français.
- Aucune donnée personnelle inventée.
- Aucun prompt complet stocké dans config/ai.php.

## JsonSchemaProvider par scénario

Chaque scénario expose son propre json_schema strict avec `additionalProperties: false`.

Mutualisation possible :
- `TaxonomyProvider` → lit categories/skills depuis DB, utilisé par les scénarios qui en ont besoin.
- Les scénarios qui n'ont pas besoin de taxonomie (ex : answer_member_profile_question) ne la chargent pas.

## Output DTO

Deux options :

### Option A : Enveloppe normalisée unique
```php
class AiScenarioResult
{
    public readonly string $scenarioKey;
    public readonly mixed $data;          // DTO spécifique au scénario
    public readonly int $inputTokens;
    public readonly int $outputTokens;
    public readonly string $model;
    public readonly float $estimatedCostUsd;
    public readonly int $latencyMs;
    public readonly ?string $error;
}
```

### Option B : DTO distincts par scénario + enveloppe
```php
class AiSupervisionResult     // existe déjà
class ClarifyHelpRequestResult
class MemberAiProfileResult
class AnswerProfileResult
```

**Décision** : Option A (enveloppe) + DTO data optionnel. Le controller et la vue n'ont pas à connaître le type exact. La vue peut switch sur `$result->scenarioKey`.

## Benchmark logger

Service léger qui enregistre pour chaque appel :
- scenario_key
- model
- input_tokens / output_tokens
- latency_ms
- estimated_cost_usd
- status (success / error)
- timestamp
- organization_id (contexte)
- user_id (admin qui a déclenché)

Stockage : log fichier structuré (JSON lines) dans un premier temps. Pas de table DB pour l'instant.

```php
class AiBenchmarkLogger
{
    public function log(string $scenarioKey, array $metrics): void;
    public function getRecent(int $limit = 50): array;
}
```

## Provider factory

Déjà partiellement présente dans `AppServiceProvider`. À faire évoluer :

```php
class AiScenarioFactory
{
    public function make(string $scenarioKey): AiScenarioDefinition;
}
```

Binding dans AppServiceProvider :
```php
$this->app->singleton(AiScenarioFactory::class, function ($app) {
    return new AiScenarioFactory($app, [
        'supervision_content' => OpenAiSupervisionProvider::class,
        'clarify_help_request' => ClarifyHelpRequestProvider::class,
        'generate_member_ai_profile' => GenerateMemberProfileProvider::class,
        'answer_member_profile_question' => AnswerProfileQuestionProvider::class,
    ]);
});
```

Le factory reste OpenAI-specific tant qu'OpenRouter n'est pas ajouté. Le provider effectif (OpenAI/OpenRouter/autre) est paramétrable via config.

---

# JSON Schémas Cibles

## 1. clarify_help_request

Output : une demande d'entraide clarifiée, alignée sur les champs produit.

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": [
    "title",
    "clarified_request",
    "help_type",
    "suggested_category",
    "suggested_loop",
    "questions_for_user",
    "publishable_draft",
    "confidence",
    "needs_human_review"
  ],
  "properties": {
    "title": {
      "type": "string",
      "description": "Titre reformulé de la demande (max 120 caractères)."
    },
    "clarified_request": {
      "type": "string",
      "description": "Demande reformulée de manière claire et actionnable. 2-4 phrases max."
    },
    "help_type": {
      "type": "string",
      "description": "Type d'aide attendue : conseil, relecture, mise en relation, retours d'expérience, ressource, coup de main, autre."
    },
    "suggested_category": {
      "type": "object",
      "additionalProperties": false,
      "required": ["slug", "label"],
      "properties": {
        "slug": { "type": "string" },
        "label": { "type": "string" }
      }
    },
    "suggested_loop": {
      "type": ["object", "null"],
      "additionalProperties": false,
      "required": ["id", "label", "reason"],
      "properties": {
        "id": { "type": "string" },
        "label": { "type": "string" },
        "reason": { "type": "string" }
      }
    },
    "questions_for_user": {
      "type": "array",
      "description": "Questions à poser au membre pour clarifier sa demande (max 3). Vide si la demande est déjà claire.",
      "items": { "type": "string" }
    },
    "publishable_draft": {
      "type": "string",
      "description": "Brouillon publiable reformulant la demande de manière claire et bienveillante, prêt à être utilisé par le membre. Vide si confiance insuffisante."
    },
    "confidence": {
      "type": "number",
      "description": "Niveau de confiance de l'analyse (0.0 à 1.0).",
      "minimum": 0,
      "maximum": 1
    },
    "needs_human_review": {
      "type": "boolean",
      "description": "true si la demande est ambiguë, hors champ, ou nécessite une validation humaine avant publication."
    }
  }
}
```

## 2. generate_member_ai_profile

Output : fiche IA bornée à **une prestation spécifique validée par le membre**.
Pour la bêta, l'IA ne fait PAS une analyse globale du membre. Elle résume uniquement ce que le membre a validé comme offre de service — une prestation précise avec son titre, sa description, sa catégorie, ses éventuelles variantes.
Le profil global et l'historique d'échanges ne sont pas utilisés pour ce scénario.

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": [
    "service_title",
    "service_summary",
    "category",
    "highlights",
    "ideal_for",
    "typical_deliverable",
    "confidence"
  ],
  "properties": {
    "service_title": {
      "type": "string",
      "description": "Titre de la prestation, repris tel quel du service validé par le membre."
    },
    "service_summary": {
      "type": "string",
      "description": "Résumé pédagogique de la prestation (2-3 phrases) basé UNIQUEMENT sur la description du service, sans ajout."
    },
    "category": {
      "type": "object",
      "additionalProperties": false,
      "required": ["slug", "label"],
      "properties": {
        "slug": { "type": "string" },
        "label": { "type": "string" },
        "description": { "type": "string" }
      }
    },
    "highlights": {
      "type": "array",
      "description": "Points forts extraits de la description du service (max 4).",
      "items": { "type": "string" }
    },
    "ideal_for": {
      "type": "string",
      "description": "Pour qui cette prestation est idéale, basé sur la description. Ex: 'Idéal pour les indépendants qui lancent leur site.'"
    },
    "typical_deliverable": {
      "type": "string",
      "description": "Livrable type si mentionné dans la description, sinon chaîne vide."
    },
    "confidence": {
      "type": "number",
      "description": "Niveau de confiance (0.0 à 1.0).",
      "minimum": 0,
      "maximum": 1
    }
  }
}
```

## 3. answer_member_profile_question

Output : réponse bornée depuis **la fiche/prestation validée** uniquement.
Ne répond pas depuis le profil global du membre, mais depuis la prestation spécifique que le membre a validée (service). Structure extrêmement contrainte.

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": [
    "answerable",
    "answer",
    "source_fields",
    "boundary_note",
    "suggestion"
  ],
  "properties": {
    "answerable": {
      "type": "boolean",
      "description": "true si la question peut être répondue depuis la fiche/prestation validée uniquement."
    },
    "answer": {
      "type": "string",
      "description": "Réponse factuelle basée UNIQUEMENT sur les données de la prestation. Vide si !answerable."
    },
    "source_fields": {
      "type": "array",
      "description": "Champs de la fiche/prestation utilisés pour répondre (ex: ['service.title', 'service.description', 'service.category']).",
      "items": { "type": "string" }
    },
    "boundary_note": {
      "type": "string",
      "description": "Note de transparence indiquant ce que l'IA a utilisé. Ex: 'Réponse basée sur la description de la prestation et la catégorie déclarée.'"
    },
    "suggestion": {
      "type": ["object", "null"],
      "additionalProperties": false,
      "required": ["type", "text"],
      "properties": {
        "type": {
          "type": "string",
          "enum": ["clarify_request", "contact_member", "create_help_request", "none"]
        },
        "text": {
          "type": "string",
          "description": "Proposition textuelle : reformulation de question, suggestion de message, invitation à publier une demande d'entraide."
        }
      }
    }
  }
}
```

---

# Règles de l'Agent IA de Profil Membre

## Principe fondamental

L'agent de profil membre est un **agent borné** : il répond uniquement depuis une **prestation spécifique validée par le membre** (un service publié). Il ne parle PAS à la place du membre. Il n'invente RIEN. Il n'utilise pas le profil global du membre pour la bêta.

## Règles strictes

### R1 — Source unique et vérifiée
L'agent répond UNIQUEMENT depuis les champs validés de la prestation (service) :
- `service.title`
- `service.description`
- `service.category` (slug + label)
- `service.skills` (compétences associées à la prestation)
- `service.tags` (tags de la prestation si présents)

### R2 — Aucune hallucination
- N'invente ni prix, ni délai, ni compétence absente de la fiche.
- N'invente pas de témoignage, d'avis ou de recommandation.
- N'invente pas de disponibilité non déclarée.
- N'invente pas de localisation.

### R3 — Pas d'usurpation
- Ne répond JAMAIS à la place du membre.
- Ne génère pas de message que le membre n'a pas écrit.
- Ne simule pas de conversation.

### R4 — Refus borné
Si la question dépasse les données de la fiche :
- `answerable = false`
- `answer = ""`
- `suggestion.type = "clarify_request"` ou `"contact_member"`
- L'agent propose de formuler une demande d'entraide claire OU de contacter le membre directement.

### R5 — Périmètre strict
L'agent refuse les questions hors périmètre :
- Juridique, médical, financier → renvoie vers les ressources adaptées.
- Avis personnel sur le membre → refuse poliment.
- Comparaison entre membres → refuse.
- Demande de contact direct → `suggestion.type = "contact_member"`.

### R6 — Transparence
- `boundary_note` toujours renseignée.
- L'utilisateur sait exactement ce qui a été utilisé pour générer la réponse.

### R7 — Organization-scopé
- L'agent ne répond QUE sur les prestations de la même Organization.
- Les prestations d'une autre Organization sont invisibles.

---

# Découpage des Tâches Suivantes

## Tâche 1 : Scenario Routing — refactor SupervisionProvider → AiScenarioDefinition
- Créer l'interface `AiScenarioDefinition`.
- Créer `AiScenarioResult` (enveloppe normalisée avec telemetry).
- Créer `AiScenarioFactory` avec binding dans AppServiceProvider.
- Refactorer `OpenAiSupervisionProvider` pour implémenter `AiScenarioDefinition`.
- Renommer `SupervisionProvider` en alias déprécié ou le faire implémenter la nouvelle interface.
- **Tests** : vérifier que le routing par scenario_key fonctionne avec supervision_content.

## Tâche 2 : Scénario clarify_help_request
- Créer `ClarifyHelpRequestProvider` implémentant `AiScenarioDefinition`.
- System prompt dédié à la clarification de demande d'entraide.
- JSON schema défini ci-dessus.
- Ajouter route admin : `POST /admin/ai-supervision/clarify` ou paramètre `?scenario=clarify_help_request`.
- Vue : carte de résultat adaptée (titre clarifié, questions, confiance).
- **Tests** : fake HTTP, validation du parse, cas vagues vs cas clairs.

## Tâche 3 : Scénario generate_member_ai_profile
- Créer `GenerateMemberProfileProvider`.
- Le payload inclut UNIQUEMENT les données de la prestation validée (service.title, service.description, service.category, service.skills).
- **Attention** : ne PAS envoyer le profil global du membre, ni son historique d'échanges, ni ses boucles.
- JSON schema défini ci-dessus (borné à une prestation).
- Route admin : POST avec `service_id` + scénario.
- **Tests** : fixture service + category, mock provider.

## Tâche 4 : Scénario answer_member_profile_question
- Créer `AnswerProfileQuestionProvider`.
- Prompt extrêmement contraint (cf règles agent profil R1-R7).
- Le payload inclut UNIQUEMENT la prestation validée + la question du visiteur.
- JSON schema défini ci-dessus (borné à la fiche/prestation).
- Le `model` doit être capable de suivre des instructions strictes (gpt-4o-mini suffit).
- **Tests** : questions dans le périmètre, hors périmètre, avec données insuffisantes.

## Tâche 5 : Dataset benchmark
- Créer `AiBenchmarkLogger`.
- Format : JSON lines vers `storage/logs/ai-benchmark-YYYY-MM-DD.log`.
- Pas de table DB.
- Vue admin : `GET /admin/ai-supervision/benchmark` avec tableau des derniers appels.
- **Tests** : écriture/lecture log, filtrage par scenario_key.

## Tâche 6 : Intégration côté membre
- Déploiement du scénario `answer_member_profile_question` sur le profil public.
- Widget "Poser une question à l'IA" sur la page `/profile/{user}`.
- Appel AJAX vers route API (pas une route web classique).
- **UI** : bouton + modal avec champ question + réponse structurée.
- **Tests** : Playwright sur le profil public.

## Tâche 7 : Stockage futur
- Quand le volume le justifie : table `ai_interactions` pour remplacer le log fichier.
- Colonnes : id, organization_id, scenario_key, model, input_tokens, output_tokens, latency_ms, cost, status, user_id (admin ou member), metadata (JSON), created_at.
- PAS avant TASK-240+.

---

# Plan d'actions immédiates (cette tâche)

- [x] inspect git status (develop, clean)
- [x] créer task + branche
- [x] lire tous les fichiers du module
- [x] produire audit complet
- [x] définir architecture cible
- [x] définir JSON schemas
- [x] définir règles agent profil
- [x] proposer découpage tâches
- [x] marquer DONE + UNLOCKED
- [x] run check-task.sh
- [x] finalize-task.sh
- [ ] attendre validation Cyril avant merge — **check Cyril OK requis avant merge**

---

# Progress Log

## 2026-06-10 19:50:13 Europe/Paris
Task created. Owner: OPENCODE. Branch: TASK-233-ai-supervision-scenarios-member-profile-agent-blueprint.

## 2026-06-10 20:10:00 Europe/Paris
Audit complet produit. Fichiers lus :
- routes/web.php
- config/ai.php
- AdminAiSupervisionController
- AdminIaDesignLabController
- SupervisionProvider interface
- OpenAiSupervisionProvider
- AiProvider interface
- FakeAIProvider
- AiSupervisionResult DTO
- AssistedInteractionLabResult DTO
- SupervisionException
- AppServiceProvider (AI bindings)
- Vue ai-supervision/index.blade.php
- AdminAiSupervisionTest.php

Décisions architecture documentées :
- AiScenarioDefinition interface
- AiScenarioFactory routing
- Option A pour output DTO (enveloppe normalisée)
- PromptBuilder dans la classe de scénario (pas stocké)
- TaxonomyProvider mutualisé
- AiBenchmarkLogger fichier-based
- Provider-agnostic mais OpenAI par défaut

JSON schemas définis pour 3 scénarios.
Règles agent de profil (R1-R7) documentées.
Découpage 7 tâches proposé.

## 2026-06-10 20:25:00 Europe/Paris
Addendum Cyril :
- clarify_help_request aligné sur les champs produit (title, clarified_request, help_type, suggested_category, suggested_loop, questions_for_user, publishable_draft, confidence, needs_human_review).
- generate_member_ai_profile corrigé : fiche IA bornée à une prestation validée, pas d'analyse globale membre.
- answer_member_profile_question précisé : répond uniquement depuis la fiche/prestation validée.
- Règle R1 resserrée sur le service (titre, description, catégorie, compétences, tags).
- "跨-Organization" remplacé par "prestations d'une autre Organization".
- Checklist interne synchronisée.
- check-task.sh ✅ finalize-task.sh ✅

# Handoffs

Pas de handoff — tâche blueprint complète par OPENCODE.

# Tests

- [ ] feature tests — non applicable (blueprint, pas de code modifié)
- [ ] browser validation — non applicable
- [ ] responsive validation — non applicable
- [ ] console inspection — non applicable
- [ ] tenant validation — non applicable

---

# Test Results

Aucun test exécuté — tâche blueprint/documentation.

---

# Review Notes

Points d'attention pour la relecture Cyril :
1. Le refactor de SupervisionProvider en AiScenarioDefinition casse-t-il l'existant ? → Non si on garde un alias ou on fait implémenter l'interface existante.
2. Faut-il un provider non-OpenAI dès la TASK-234 ? → Non, on garde OpenAI Responses API pour tous les scénarios. OpenRouter sera ajouté plus tard si besoin.
3. Les JSON schemas sont-ils assez contraints pour éviter les hallucinations ? → Oui, surtout answer_member_profile_question avec `answerable` booléen et champs stricts.
4. Le benchmark logger fichier-based suffit-il pour le MVP ? → Oui, pas de table DB avant TASK-240+.
5. La vue admin actuelle est-elle conservée ou remplacée ? → Conservée pour supervision_content. Les nouveaux scénarios auront leurs propres vues ou composants Livewire.

Risques :
- **Dérive scope** : ne pas transformer en chatbot généraliste → les règles R3-R5 bloquent ça.
- **Hallucination profil** : le profil généré doit être marqué "Généré par IA — non vérifié" jusqu'à validation humaine.
- **Performance** : certains scénarios (profile generation) peuvent consommer plus de tokens → prévoir timeout configurable par scénario.
- **Dépendance OpenAI** : si OpenAI est down, tous les scénarios sont bloqués → le factory prépare l'agnostic mais sans fallback provider.
- **Organization isolation** : les prestations d'une autre Organization ne doivent pas être accessibles → à vérifier dans le controller.

La prochaine micro-tâche recommandée est **TASK-234 : Scenario Routing — refactor SupervisionProvider → AiScenarioDefinition**.
