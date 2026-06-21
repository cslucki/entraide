<?php

namespace App\Services\Ai\Scenarios;

use App\Models\AdminAiPrompt;
use App\Models\Category;
use App\Models\Skill;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use Illuminate\Support\Facades\Schema;

class SupervisionContentScenario implements AiScenarioDefinition
{
    private const BASE_SYSTEM_PROMPT = <<<'PROMPT'
Tu es un assistant de supervision pour des administrateurs d'une plateforme
collaborative française. Tu reçois un extrait de contenu produit par un membre
(message, demande, post) et tu produis une analyse courte et structurée pour
aider l'administrateur à décider d'une action.

Règles générales :
- Réponse exclusivement en français.
- Aucune donnée personnelle inventée.
- Reste factuel, ne juge pas la personne.
- Ne propose pas d'action légale ou médicale.
- Si le contenu est ambigu ou trop court, dis-le explicitement.

Règles de catégorisation :
- Mapper vers la catégorie la plus appropriée via son slug exact issu de la taxonomie ci-dessous.
- Si la confiance est insuffisante ou le contenu trop ambigu, utiliser slug "autre".
- Placer dans unmatched_terms les termes spécifiques du contenu sans correspondance claire.
- Mettre needs_human_category_review = true si le mapping est incertain ou si plusieurs
  catégories sont plausibles à parts égales.
- Ne jamais inventer un slug hors de la liste officielle.
- Si aucune compétence secondaire ne correspond, retourner un tableau vide pour skills.
PROMPT;

    public function id(): string
    {
        return 'supervision_content';
    }

    public function name(): string
    {
        return 'Supervision de contenu';
    }

    public function description(): ?string
    {
        return 'Analyse un extrait de contenu membre pour en extraire la catégorie, les compétences, le niveau de risque et des recommandations.';
    }

    public function providerHint(): string
    {
        return 'openai';
    }

    public function systemPrompt(): string
    {
        $prompt = null;
        if (Schema::hasTable('admin_ai_prompts')) {
            $found = AdminAiPrompt::active()->byScenario($this->id())->orderByDesc('version')->first();
            if ($found) {
                $prompt = $found->prompt_text;
            }
        }

        $basePrompt = $prompt ?? self::BASE_SYSTEM_PROMPT;

        $taxonomy = $this->loadTaxonomy();
        $categories = $taxonomy['categories'];
        $skills = $taxonomy['skills'];

        $categoryLines = implode("\n", array_map(
            fn ($c) => '- '.($c['slug'] ?? '').' : '.($c['label'] ?? ''),
            $categories
        ));

        $skillLines = implode("\n", array_map(
            fn ($s) => '- '.($s['slug'] ?? '').' : '.($s['label'] ?? ''),
            $skills
        ));

        return $basePrompt
            ."\n\nTaxonomie officielle des catégories (utilise UNIQUEMENT ces slugs exacts) :\n"
            .$categoryLines
            ."\n\nCompétences secondaires disponibles (enrichissement uniquement, liste non exhaustive) :\n"
            .$skillLines;
    }

    public function jsonSchema(): array
    {
        $taxonomy = $this->loadTaxonomy();

        $categorySlugs = array_values(array_column($taxonomy['categories'], 'slug'));
        if ($categorySlugs === []) {
            $categorySlugs = ['tech-digital', 'design', 'marketing', 'redaction', 'conseil', 'formation', 'traduction', 'autre'];
        }

        $skillSlugs = array_values(array_column($taxonomy['skills'], 'slug'));
        if ($skillSlugs === []) {
            $skillSlugs = ['articles-de-blog', 'redaction-technique', 'correctionrelecture', 'copywriting', 'ateliers-creatifs'];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary', 'risk_level', 'category', 'skills', 'unmatched_terms',
                'needs_human_category_review', 'category_review_reason',
                'recommendations', 'moderation_flag', 'notes',
            ],
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Résumé neutre du contenu (1 à 2 phrases).',
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high'],
                ],
                'category' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['slug', 'label'],
                    'properties' => [
                        'slug' => [
                            'type' => 'string',
                            'enum' => $categorySlugs,
                            'description' => 'Slug exact de la taxonomie officielle BouclePro.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Libellé lisible correspondant au slug.',
                        ],
                    ],
                ],
                'skills' => [
                    'type' => 'array',
                    'description' => 'Compétences secondaires pertinentes extraites de la liste connue. Tableau vide si aucune ne correspond.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['slug', 'label'],
                        'properties' => [
                            'slug' => [
                                'type' => 'string',
                                'enum' => $skillSlugs,
                                'description' => 'Slug exact de la liste de compétences BouclePro.',
                            ],
                            'label' => ['type' => 'string'],
                        ],
                    ],
                ],
                'unmatched_terms' => [
                    'type' => 'array',
                    'description' => 'Termes spécifiques du contenu sans correspondance dans la taxonomie officielle.',
                    'items' => ['type' => 'string'],
                ],
                'needs_human_category_review' => [
                    'type' => 'boolean',
                    'description' => 'true si le mapping catégorie est incertain ou si plusieurs catégories sont plausibles.',
                ],
                'category_review_reason' => [
                    'type' => 'string',
                    'description' => 'Raison courte justifiant la demande de révision humaine, vide sinon.',
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'moderation_flag' => [
                    'type' => 'boolean',
                ],
                'notes' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    private function loadTaxonomy(): array
    {
        $categories = [];
        $skills = [];

        if (Schema::hasTable('categories')) {
            $categories = Category::select('slug', 'name_b2c as label')
                ->orderBy('slug')
                ->get()
                ->each(fn ($c) => $c->label = $c->label ?? '')
                ->toArray();
        }

        if (Schema::hasTable('skills')) {
            $skills = Skill::select('slug', 'name as label')
                ->orderBy('slug')
                ->get()
                ->each(fn ($s) => $s->label = $s->label ?? '')
                ->toArray();
        }

        return [
            'categories' => $categories ?: config('ai.supervision.taxonomy.categories', []),
            'skills' => $skills ?: config('ai.supervision.taxonomy.skills', []),
        ];
    }
}
