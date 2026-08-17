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
        'economic_guard' => [
            'monthly_budget_usd' => (float) env('AI_KNOWLEDGE_MONTHLY_BUDGET_USD', 2.00),
            'monthly_unknown_limit' => (int) env('AI_KNOWLEDGE_MONTHLY_UNKNOWN_LIMIT', 10),
        ],
    ],

    'chatloop' => [
        'enabled' => (bool) env('CHATLOOP_AI_ENABLED', true),
        'scenario' => env('CHATLOOP_AI_SCENARIO', 'chatloop_ai_answer'),
        'ask_scenario' => env('CHATLOOP_AI_ASK_SCENARIO', 'chatloop_ai_ask'),
        'summarize_scenario' => env('CHATLOOP_AI_SUMMARIZE_SCENARIO', 'chatloop_ai_summarize'),
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
    ],

];
