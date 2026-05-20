<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiSupervisionProvider implements SupervisionProvider
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

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxOutputTokens,
        private readonly int $timeout,
        private readonly float $inputPricePer1M,
        private readonly float $outputPricePer1M,
    ) {}

    public function supervise(string $content): AiSupervisionResult
    {
        if ($this->apiKey === '') {
            throw new SupervisionException('Clé API OpenAI manquante.');
        }

        $payload = $this->buildPayload($content);

        $startedAt = (int) (microtime(true) * 1000);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').'/responses', $payload);
        } catch (ConnectionException $e) {
            throw new SupervisionException('Connexion OpenAI impossible.', 0, $e);
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        if ($response->failed()) {
            throw new SupervisionException(
                sprintf('Réponse OpenAI invalide (HTTP %d).', $response->status())
            );
        }

        $body = $response->json();

        $text = $this->extractOutputText($body);
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            throw new SupervisionException('Sortie JSON OpenAI non décodable.');
        }

        $inputTokens = (int) data_get($body, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($body, 'usage.output_tokens', 0);

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
            model: (string) data_get($body, 'model', $this->model),
            estimatedCostUsd: $this->estimateCost($inputTokens, $outputTokens),
            latencyMs: $latencyMs,
        );
    }

    private function buildPayload(string $content): array
    {
        return [
            'model' => $this->model,
            'max_output_tokens' => $this->maxOutputTokens,
            'store' => false,
            'input' => [
                [
                    'role' => 'system',
                    'content' => $this->buildSystemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'ai_supervision_result',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
                ],
            ],
        ];
    }

    private function buildSystemPrompt(): string
    {
        $categories = config('ai.supervision.taxonomy.categories', []);
        $skills = config('ai.supervision.taxonomy.skills', []);

        $categoryLines = implode("\n", array_map(
            fn ($c) => '- '.($c['slug'] ?? '').' : '.($c['label'] ?? ''),
            $categories
        ));

        $skillLines = implode("\n", array_map(
            fn ($s) => '- '.($s['slug'] ?? '').' : '.($s['label'] ?? ''),
            $skills
        ));

        return self::BASE_SYSTEM_PROMPT
            ."\n\nTaxonomie officielle des catégories (utilise UNIQUEMENT ces slugs exacts) :\n"
            .$categoryLines
            ."\n\nCompétences secondaires disponibles (enrichissement uniquement, liste non exhaustive) :\n"
            .$skillLines;
    }

    private function jsonSchema(): array
    {
        $categorySlugs = array_values(array_column(
            config('ai.supervision.taxonomy.categories', []),
            'slug'
        ));

        if ($categorySlugs === []) {
            $categorySlugs = ['tech-digital', 'design', 'marketing', 'redaction', 'conseil', 'formation', 'traduction', 'autre'];
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
                            'slug' => ['type' => 'string'],
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

    private function extractOutputText(array $body): string
    {
        if (isset($body['output_text']) && is_string($body['output_text']) && $body['output_text'] !== '') {
            return $body['output_text'];
        }

        $output = $body['output'] ?? [];
        foreach ($output as $item) {
            $contents = $item['content'] ?? [];
            foreach ($contents as $piece) {
                if (($piece['type'] ?? null) === 'output_text' && isset($piece['text'])) {
                    return (string) $piece['text'];
                }
            }
        }

        throw new SupervisionException('Aucun output_text dans la réponse OpenAI.');
    }

    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        $cost = ($inputTokens / 1_000_000) * $this->inputPricePer1M
            + ($outputTokens / 1_000_000) * $this->outputPricePer1M;

        return round($cost, 6);
    }
}
