<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI — Admin AI Supervision Center
    |--------------------------------------------------------------------------
    |
    | Configuration centralisée pour les appels OpenAI utilisés par le centre
    | de supervision IA admin. Les clés `input_price_per_1m` et
    | `output_price_per_1m` permettent d'afficher un coût estimé après chaque
    | appel (USD par million de tokens, source : tarifs publics du modèle).
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 900),
        'timeout' => (int) env('OPENAI_TIMEOUT', 15),
        'input_price_per_1m' => (float) env('OPENAI_INPUT_PRICE_PER_1M', 0.15),
        'output_price_per_1m' => (float) env('OPENAI_OUTPUT_PRICE_PER_1M', 0.60),
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

];
