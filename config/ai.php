<?php

return [

    'default_provider' => env('AI_DEFAULT_PROVIDER', 'openai'),
    'default_model' => env('AI_DEFAULT_MODEL'),
    'default_for_embeddings' => env('AI_EMBEDDING_PROVIDER', 'openai'),

    'caching' => [
        'embeddings' => [
            'cache' => (bool) env('AI_EMBEDDING_CACHE_ENABLED', false),
            'store' => env('AI_EMBEDDING_CACHE_STORE', env('CACHE_STORE', 'database')),
            'seconds' => (int) env('AI_EMBEDDING_CACHE_SECONDS', 60 * 60 * 24 * 30),
        ],
    ],

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')),
            'models' => [
                'embeddings' => [
                    'default' => env('AI_EMBEDDING_MODEL', 'text-embedding-3-small'),
                    'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
                ],
            ],
        ],
        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'models' => [
                'embeddings' => [
                    'default' => env('AI_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
                    'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
                ],
            ],
        ],
    ],

    'dossiers' => [
        'semantic_search' => [
            'enabled' => (bool) env('DOSSIER_SEMANTIC_SEARCH_ENABLED', false),
            'organization_ids' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DOSSIER_SEMANTIC_SEARCH_ORGANIZATION_IDS', '')),
            ))),
            'organization_slugs' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DOSSIER_SEMANTIC_SEARCH_ORGANIZATION_SLUGS', '')),
            ))),
            'limit' => 5,
        ],
    ],

    /*

    /*
    |--------------------------------------------------------------------------
    | OpenAI — Admin AI Supervision Center
    |--------------------------------------------------------------------------
    |
    | Configuration centralisée pour les appels OpenAI utilisés par le centre
    | de supervision IA admin.
    |
    | TASK-1132 / IA P1-2 — les tarifs ne vivent PLUS ici.
    | Les clés `input_price_per_1m` / `output_price_per_1m` portaient des défauts
    | en dur (0.15 / 0.60) qui masquaient l'absence de tarif : un provider sans
    | prix déclaré produisait un coût de 0 indiscernable d'un modèle gratuit.
    |
    | Le tarif est désormais lu dans le catalogue versionné
    | `config/ai_pricing.php` via `App\Support\Ai\AiPricingCatalog`, qui distingue
    | explicitement un tarif connu d'un tarif inconnu. La surcharge opérateur
    | OPENAI_INPUT_PRICE_PER_1M / OPENAI_OUTPUT_PRICE_PER_1M reste honorée, mais
    | depuis `ai_pricing.overrides.openai` et sans défaut fabriqué.
    |
    */

    'openai' => [
        'supervision_enabled' => (bool) env('OPENAI_SUPERVISION_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 900),
        'timeout' => (int) env('OPENAI_TIMEOUT', 15),
    ],

    'supervision' => [
        'enabled' => (bool) env('AI_SUPERVISION_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Taxonomy snapshot — 2026-05-20 DB audit
        |----------------------------------------------------------------------
        |
        | Source: categories table (slug/name), skills table (slug/name).
        | services.category_id, service_requests.category_id,
        | blog_post_category → all reference this same categories table.
        | Tags are free-form and must NOT be used as controlled taxonomy.
        |
        | Future task: replace with a CategoryTaxonomyProvider reading
        | categories and skills from DB (read-only), once T078.x stabilises.
        |
        */
        'taxonomy' => [
            'categories' => [
                ['slug' => 'tech-digital', 'label' => 'Tech & Digital'],
                ['slug' => 'design',        'label' => 'Design'],
                ['slug' => 'marketing',     'label' => 'Marketing'],
                ['slug' => 'redaction',     'label' => 'Rédaction'],
                ['slug' => 'conseil',       'label' => 'Conseil'],
                ['slug' => 'formation',     'label' => 'Formation'],
                ['slug' => 'traduction',    'label' => 'Traduction'],
                ['slug' => 'autre',         'label' => 'Autre (si aucune catégorie ne correspond avec assez de confiance)'],
            ],
            // Limited audited subset relevant to current admin supervision (T078.1).
            // Source: skills table, 2026-05-20 DB audit. Only slugs directly observed
            // in the audit are listed here. Do NOT add slugs that were not audited.
            'skills' => [
                ['slug' => 'articles-de-blog',    'label' => 'Articles de blog'],
                ['slug' => 'redaction-technique', 'label' => 'Rédaction technique'],
                ['slug' => 'correctionrelecture', 'label' => 'Correction/Relecture'],
                ['slug' => 'copywriting',         'label' => 'Copywriting'],
                ['slug' => 'ateliers-creatifs',   'label' => 'Ateliers créatifs'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama — Local LLM (admin AI lab only, experimental)
    |--------------------------------------------------------------------------
    |
    | Provider expérimental pour exécuter la supervision via un modèle local
    | Ollama. Désactivé par défaut. Usage exclusif au centre de supervision IA
    | admin (/admin/ai-supervision).
    |
    */

    'ollama' => [
        'enabled' => (bool) env('OLLAMA_ENABLED', false),
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'ministral-3:3b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenRouter — Multi-model proxy (admin AI lab only, experimental)
    |--------------------------------------------------------------------------
    |
    | Provider expérimental pour exécuter la supervision via l'API OpenRouter
    | (proxy multi-modèles). Désactivé par défaut. Usage exclusif au centre de
    | supervision IA admin (/admin/ai-supervision).
    |
    */

    'openrouter' => [
        'enabled' => (bool) env('OPENROUTER_ENABLED', false),
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-chat-v3-0324'),
        'max_output_tokens' => (int) env('OPENROUTER_MAX_OUTPUT_TOKENS', 900),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
        'site_name' => env('APP_NAME', 'BouclePro'),
        'site_url' => env('APP_URL', 'http://localhost'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Alerts — Monthly AI cost thresholds per scenario
    |--------------------------------------------------------------------------
    |
    | Monthly cost limits per scenario_id. When the current month's cost exceeds
    | the threshold, an email alert is sent to all admin users.
    | Values are in USD. Set to 0 or omit to disable alerts for a scenario.
    |
    */

    'budget_alerts' => [
        'supervision_content' => (float) env('AI_BUDGET_SUPERVISION_CONTENT', 5.00),
        'clarify_help_request' => (float) env('AI_BUDGET_CLARIFY_HELP_REQUEST', 2.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | User-facing clarification — Loop help request AI analysis
    |--------------------------------------------------------------------------
    |
    | When enabled, user help request intentions are analyzed via the real
    | ClarifyHelpRequestScenario (OpenAI/Ollama/OpenRouter) instead of the
    | keyword-matching FakeAIProvider. Disabled by default to avoid API costs
    | in development.
    |
    */

    'clarify' => [
        'enabled' => (bool) env('AI_CLARIFY_ENABLED', false),
        'max_context_chars' => (int) env('AI_CLARIFY_MAX_CONTEXT_CHARS', 8000),
        // TASK-1212 : meme garde economique que le resume ChatLoop, par
        // process et par Organization (voir AiEconomicGuard).
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('AI_CLARIFY_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('AI_CLARIFY_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings — garde economique (TASK-1222)
    |--------------------------------------------------------------------------
    |
    | L'ingestion documentaire est refusee AVANT tout appel provider quand le
    | plafond mensuel de l'Organization est atteint (generation gardee dans
    | `ai_interactions` + embeddings connus du ledger canonique), ou quand le
    | compteur mensuel d'invocations embeddings a cout INCONNU depasse cette
    | limite — un cout que le catalogue ne sait pas mesurer ne devient jamais
    | un droit de consommation illimite.
    */
    'embeddings' => [
        'economic_guard' => [
            'monthly_unknown_limit' => (int) env('AI_EMBEDDINGS_MONTHLY_UNKNOWN_LIMIT', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FAB « BouclePro IA » (TASK-1231)
    |--------------------------------------------------------------------------
    |
    | Point d'entree unique et contextuel des capabilities IA existantes dans
    | le layout membre. Il n'appelle jamais un provider : il ouvre des
    | surfaces qui existent et affiche le credit utilisateur (autorite :
    | AiEconomicGuard::userCreditStatus). Kill-switch plateforme, sans etat.
    */
    'fab' => [
        'enabled' => (bool) env('AI_FAB_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shell « BouclePro IA » (TASK-1315)
    |--------------------------------------------------------------------------
    |
    | Le Shell est une SURFACE, pas un moteur : son tour de conversation
    | delegue a `ClarifyUserHelpRequestService::clarifyForOrganization()`, dont
    | la garde economique, le budget et le ledger restent ceux de la
    | clarification. Aucune cle de budget ici — il n'y en a pas a inventer.
    |
    | `max_thread_messages` borne la FENETRE affichee et relue ; le fil est
    | elague au-dela du double. Il n'y a ni resume ni rappel d'un fil a
    | l'autre : « memoire avancee » est hors V1.
    |
    */
    'shell' => [
        'enabled' => (bool) env('AI_SHELL_ENABLED', true),
        'max_thread_messages' => (int) env('AI_SHELL_MAX_THREAD_MESSAGES', 40),
        'max_input_chars' => (int) env('AI_SHELL_MAX_INPUT_CHARS', 2000),
        // TASK-1326 : borne du contexte epingle. La limite est STRUCTURELLE
        // (AiShellPinnedContext tronque a la relecture) : la reduire retire
        // d'office les pins excedentaires.
        'max_pins' => (int) env('AI_SHELL_MAX_PINS', 3),
        // Meme forme que `ai.chatloop.lock_ttl` : jamais sous le timeout
        // provider + 30 s, sinon le verrou expire pendant la generation.
        'lock_ttl' => (int) env('AI_SHELL_LOCK_TTL', 90),
        'timeout' => (int) env('AI_SHELL_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit IA par utilisateur — defauts plateforme (TASK-1229)
    |--------------------------------------------------------------------------
    |
    | Le credit commercial d'un utilisateur se compte en NOMBRE D'UTILISATIONS
    | par mois (fenetre UTC du budget), jamais en monnaie. Ces valeurs ne
    | servent que tant que le SuperAdmin n'a rien enregistre dans « Monetisation
    | IA » (`ai_configs`, cles `user_credit.*`) ; une Organization peut ensuite
    | les surcharger (valeur propre / illimite). `monthly_uses` vide = illimite :
    | par defaut, rien ne bloque.
    */
    'user_credit' => [
        'free_enabled' => (bool) env('AI_USER_CREDIT_FREE_ENABLED', true),
        'monthly_uses' => env('AI_USER_CREDIT_MONTHLY_USES'),
        'alert_percent' => (int) env('AI_USER_CREDIT_ALERT_PERCENT', 80),
        'offer_subscription' => (bool) env('AI_USER_CREDIT_OFFER_SUBSCRIPTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | ChatLoop AI — Persistent AI intervention in loops
    |--------------------------------------------------------------------------
    |
    | Bounds for the ChatLoop AI answer feature. Context is built from the
    | last `max_context_messages` loop messages (oldest first) and truncated
    | to `max_context_chars`. The LLM answer is capped by `max_tokens` and the
    | sanitized, persisted body by `max_response_chars`. One simultaneous
    | generation per loop is enforced through a short-lived cache lock.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Loop knowledge answer — RAG V1 (TASK-1213)
    |--------------------------------------------------------------------------
    |
    | Reponse documentaire sourcee depuis une Boucle : au plus `top_k` extraits
    | (<= 5) des Dossiers accessibles, distance pgvector <= `max_distance`
    | (cosinus ; 0.60 calibre sur le corpus ArtSciLab avec
    | text-embedding-3-small : pertinent 0.32-0.57, hors corpus >= 0.62),
    | contexte borne a `max_context_chars`. Meme garde economique que les
    | autres capabilities.
    |
    */

    'knowledge' => [
        'top_k' => (int) env('AI_KNOWLEDGE_TOP_K', 5),
        'max_distance' => (float) env('AI_KNOWLEDGE_MAX_DISTANCE', 0.60),
        'max_context_chars' => (int) env('AI_KNOWLEDGE_MAX_CONTEXT_CHARS', 6000),
        'max_tokens' => (int) env('AI_KNOWLEDGE_MAX_TOKENS', 700),
        'temperature' => (float) env('AI_KNOWLEDGE_TEMPERATURE', 0.2),
        'max_answer_chars' => (int) env('AI_KNOWLEDGE_MAX_ANSWER_CHARS', 3000),
        // TASK-1297 : publier la question du membre avec la reponse (modele
        // ask()). Reversible en UNE ligne si l'arbitrage produit tranche
        // autrement : false = seule la reponse IA est publiee.
        'publish_question' => (bool) env('AI_KNOWLEDGE_PUBLISH_QUESTION', true),
        /*
         * TASK-1309 — vue d'ensemble documentaire.
         *
         * Une question panoramique n'a aucun bon voisin vectoriel : la
         * selection semantique sort vide et le mode Dossiers ne peut plus
         * parler que de metadonnees. `DossierRetrievalSource` reconstruit
         * alors la selection en LARGEUR — un extrait court par document, au
         * plus `max_documents` documents. Ces deux nombres bornent la vue
         * d'ensemble ; ils ne touchent NI `top_k`, NI `max_distance`, et
         * n'entrainent aucun appel provider supplementaire (le complement est
         * une lecture SQL, sans embedding).
         */
        'overview' => [
            'max_documents' => (int) env('AI_KNOWLEDGE_OVERVIEW_MAX_DOCUMENTS', 6),
            'chars_per_document' => (int) env('AI_KNOWLEDGE_OVERVIEW_CHARS_PER_DOCUMENT', 700),
        ],
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('AI_KNOWLEDGE_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('AI_KNOWLEDGE_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Doctrine de l'Organization (TASK-1227)
    |--------------------------------------------------------------------------
    |
    | Texte editable par l'Admin Organization, compose par PromptRepository
    | SOUS la Constitution. `max_chars` borne le champ (validation HTTP) et la
    | composition (defensive). `sandbox_per_minute` limite les tests reels
    | « tester sans publier » par utilisateur.
    |
    */

    'doctrine' => [
        'max_chars' => (int) env('AI_DOCTRINE_MAX_CHARS', 4000),
        'sandbox_per_minute' => (int) env('AI_DOCTRINE_SANDBOX_PER_MINUTE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blog IA — autorite economique (TASK-1247, TASK-1248)
    |--------------------------------------------------------------------------
    |
    | `BlogAiService` (generation, correction, methode sur selection — T1247)
    | et `BlogExplorerController` (dialogue Explorer, note d'analyse — T1248)
    | passent sous `AiEconomicGuard` AVANT tout appel provider : budget mensuel
    | de l'Organization, budget/quota d'inconnus PAR PROCESS (`blog.*` :
    | article_generate, article_correct, method_selection, explorer_dialogue,
    | explorer_note — le budget ci-dessous s'applique a chacun separement) et
    | credit IA du demandeur — la meme autorite que les capabilities canoniques.
    | Chemins HERITES : cle plateforme (declaree telle quelle au ledger), pas de
    | Constitution/doctrine ; la migration BYOK est hors V1 (BLOC E).
    |
    */

    'blog' => [
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('BLOG_AI_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('BLOG_AI_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
        // TASK-1284 : budget de contexte des capabilities blog_generate /
        // blog_correct (source blog.post). Large a dessein : le materiau est
        // l'article lui-meme, et la correction doit recevoir le texte ENTIER —
        // la source laisse toujours passer sa premiere unite en entier (meme
        // regle que loop.messages), ce plafond ne borne que les unites suivantes.
        'max_context_chars' => (int) env('BLOG_AI_MAX_CONTEXT_CHARS', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent de profil membre — capabilities canoniques (TASK-1285)
    |--------------------------------------------------------------------------
    |
    | Budget de contexte des capabilities member_profile_agent_loop_reply /
    | member_profile_agent_visitor_chat (source member.profile). Le materiau
    | est le profil IA publie : ses champs sont bornes par le produit, le bloc
    | reste tres en deca de ce plafond. La source laisse passer son unite
    | unique EN ENTIER (regle de la premiere unite, comme blog.post) : avant
    | migration le profil partait toujours entier, le tronquer aurait ete un
    | changement de comportement.
    */

    'member_profile' => [
        'max_context_chars' => (int) env('MEMBER_PROFILE_AI_MAX_CONTEXT_CHARS', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chemins herites SupervisionProviderResolver — autorite economique (TASK-1250)
    |--------------------------------------------------------------------------
    |
    | Les chemins AUTHENTIFIES de la famille C du gap analysis T1246 passent
    | sous `AiEconomicGuard` AVANT tout appel provider, avec le MEME budget
    | applique PAR PROCESS : formulation d'offre de service
    | (`service_offer.master`, credit IA du membre applique), test LLM d'un
    | profil par un administrateur (`member_profile.admin_llm_test`, tenant =
    | Organization du profil, sans credit) et banc de supervision SuperAdmin
    | (`supervision.content` / `help_request.clarify`, tenant = Organization
    | plateforme `is_default`, sans credit). Cle plateforme declaree telle
    | quelle au ledger (`credential_source = platform`) ; ni Constitution ni
    | BYOK (BLOC E). Le budget mensuel de l'Organization de record s'applique
    | par-dessus, comme partout.
    |
    */

    'supervision_resolver' => [
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('AI_SUPERVISION_RESOLVER_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('AI_SUPERVISION_RESOLVER_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
    ],

    'chatloop' => [
        'enabled' => (bool) env('CHATLOOP_AI_ENABLED', true),
        'scenario' => env('CHATLOOP_AI_SCENARIO', 'chatloop_ai_answer'),
        'ask_scenario' => env('CHATLOOP_AI_ASK_SCENARIO', 'chatloop_ai_ask'),
        'summarize_scenario' => env('CHATLOOP_AI_SUMMARIZE_SCENARIO', 'chatloop_ai_summarize'),
        // TASK-1327 : suggestion de capitalisation d'une Decision (Premium-1).
        // Temperature basse dediee : une extraction bornee, pas une redaction.
        'decision_suggestion_scenario' => env('CHATLOOP_AI_DECISION_SUGGESTION_SCENARIO', 'loop_decision_suggestion'),
        'decision_suggestion_temperature' => (float) env('CHATLOOP_AI_DECISION_SUGGESTION_TEMPERATURE', 0.2),
        'min_summary_words' => (int) env('CHATLOOP_AI_MIN_SUMMARY_WORDS', 30),
        'max_context_messages' => (int) env('CHATLOOP_AI_MAX_CONTEXT_MESSAGES', 30),
        'max_context_chars' => (int) env('CHATLOOP_AI_MAX_CONTEXT_CHARS', 12000),
        'max_tokens' => (int) env('CHATLOOP_AI_MAX_TOKENS', 2048),
        'max_response_chars' => (int) env('CHATLOOP_AI_MAX_RESPONSE_CHARS', 8000),
        'timeout' => (int) env('CHATLOOP_AI_TIMEOUT', 30),
        'temperature' => (float) env('CHATLOOP_AI_TEMPERATURE', 0.7),
        'max_simultaneous' => (int) env('CHATLOOP_AI_MAX_SIMULTANEOUS', 1),
        'lock_ttl' => (int) env('CHATLOOP_AI_LOCK_TTL', 90),
        'summary_economic_guard' => [
            'monthly_budget_usd' => (float) env('CHATLOOP_AI_SUMMARY_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('CHATLOOP_AI_SUMMARY_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
        // TASK-1231 (lot 0) : « Demander a l'IA » (ask / answer, chemin herite)
        // passe sous AiEconomicGuard comme le resume — meme autorite, meme
        // demandeur, budget par process (chatloop.ask / chatloop.answer) et
        // credit utilisateur, AVANT tout appel provider. N'ajoute que le
        // blocage : ces chemins comptaient deja, ils ne comptent pas deux fois.
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('CHATLOOP_AI_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('CHATLOOP_AI_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
    ],

];
