<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OpenRouterSupervisionProvider implements SupervisionProvider
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

    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxOutputTokens = 900,
        private readonly int $timeout = 30,
        private readonly string $siteName = '',
        private readonly string $siteUrl = '',
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        if ($this->apiKey === '') {
            throw new SupervisionException('Clé API OpenRouter manquante.');
        }

        $resolvedModel = $model ?? $this->model;

        $taxonomy = $this->loadTaxonomyFromDb();
        $systemPrompt = $this->buildSystemPrompt($taxonomy);
        $jsonSchema = $this->buildJsonSchema($taxonomy);

        $payload = [
            'model' => $resolvedModel,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $content],
            ],
            'max_tokens' => $this->maxOutputTokens,
            'temperature' => 0.3,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'supervision',
                    'strict' => true,
                    'schema' => $jsonSchema,
                ],
            ],
        ];

        $startedAt = (int) (microtime(true) * 1000);

        $attempts = 0;

        while ($attempts < self::MAX_RETRIES) {
            $attempts++;

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'HTTP-Referer' => $this->siteUrl,
                    'X-Title' => $this->siteName,
                ])
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($this->baseUrl, '/') . '/chat/completions', $payload);

                $status = $response->status();

                if ($status === 429) {
                    usleep(($attempts ** 2) * 100000);
                    continue;
                }

                if (! $response->successful()) {
                    throw new SupervisionException(sprintf(
                        'Réponse OpenRouter invalide (HTTP %d).', $status
                    ));
                }

                break;
            } catch (ConnectionException $e) {
                if ($attempts >= self::MAX_RETRIES) {
                    throw new SupervisionException('Connexion OpenRouter impossible après plusieurs tentatives.', 0, $e);
                }
                usleep(($attempts ** 2) * 100000);
                continue;
            }
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        $body = $response->json();

        $text = $body['choices'][0]['message']['content'] ?? '';
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            throw new SupervisionException('Sortie JSON OpenRouter non décodable.');
        }

        $inputTokens = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($body['usage']['completion_tokens'] ?? 0);

        $estimatedCostUsd = round(
            ($inputTokens / 1_000_000) * 0.15 + ($outputTokens / 1_000_000) * 0.60,
            6
        );

        $rawCategory = $parsed['category'] ?? [];
        $category = [
            'slug' => (string) ($rawCategory['slug'] ?? 'autre'),
            'label' => (string) ($rawCategory['label'] ?? 'Autre'),
        ];

        $skills = array_values(array_map(
            fn ($s) => [
                'slug' => (string) ($s['slug'] ?? ''),
                'label' => (string) ($s['label'] ?? ''),
            ],
            array_filter((array) ($parsed['skills'] ?? []), 'is_array')
        ));

        return new AiSupervisionResult(
            summary: (string) ($parsed['summary'] ?? ''),
            riskLevel: (string) ($parsed['risk_level'] ?? 'low'),
            category: $category,
            skills: $skills,
            unmatchedTerms: array_values(array_map('strval', (array) ($parsed['unmatched_terms'] ?? []))),
            needsHumanCategoryReview: (bool) ($parsed['needs_human_category_review'] ?? false),
            categoryReviewReason: (string) ($parsed['category_review_reason'] ?? ''),
            recommendations: array_values(array_map('strval', (array) ($parsed['recommendations'] ?? []))),
            moderationFlag: (bool) ($parsed['moderation_flag'] ?? false),
            notes: (string) ($parsed['notes'] ?? ''),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $resolvedModel,
            estimatedCostUsd: $estimatedCostUsd,
            latencyMs: $latencyMs,
        );
    }

    private function loadTaxonomyFromDb(): array
    {
        $categories = [];
        $skills = [];

        if (Schema::hasTable('categories')) {
            $categories = \App\Models\Category::select('slug', 'name_b2c as label')
                ->orderBy('slug')
                ->get()
                ->each(fn ($c) => $c->label = $c->label ?? '')
                ->toArray();
        }

        if (Schema::hasTable('skills')) {
            $skills = \App\Models\Skill::select('slug', 'name as label')
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

    private function buildSystemPrompt(array $taxonomy): string
    {
        $categories = $taxonomy['categories'];
        $skills = $taxonomy['skills'];

        $categoryLines = implode("\n", array_map(
            fn ($c) => '- ' . ($c['slug'] ?? '') . ' : ' . ($c['label'] ?? ''),
            $categories
        ));

        $skillLines = implode("\n", array_map(
            fn ($s) => '- ' . ($s['slug'] ?? '') . ' : ' . ($s['label'] ?? ''),
            $skills
        ));

        return self::BASE_SYSTEM_PROMPT
            . "\n\nTaxonomie officielle des catégories (utilise UNIQUEMENT ces slugs exacts) :\n"
            . $categoryLines
            . "\n\nCompétences secondaires disponibles (enrichissement uniquement, liste non exhaustive) :\n"
            . $skillLines;
    }

    private function buildJsonSchema(array $taxonomy): array
    {
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
}
