<?php

namespace App\Services\Ai\Providers;

use App\Models\Category;
use App\Models\Skill;
use App\Services\Ai\Contracts\AiScenarioDefinition;
use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\JsonResponseParser;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OpenAiSupervisionProvider implements SupervisionProvider
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_MS = 1000;

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

    /**
     * TASK-1132 : plus aucun tarif injecté ici. Les prix venaient de
     * `config/ai.php` avec des défauts en dur dupliqués dans AppServiceProvider,
     * ce qui rendait un tarif manquant indiscernable d'un modèle gratuit. Le
     * tarif est maintenant lu par modèle dans le catalogue versionné.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $maxOutputTokens,
        private readonly int $timeout,
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        if ($this->apiKey === '') {
            throw new SupervisionException('Clé API OpenAI manquante.');
        }

        $payload = $this->buildPayload($content, $model ?? $this->model);

        $startedAt = (int) (microtime(true) * 1000);

        $attempt = 0;
        $response = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($this->baseUrl, '/').'/responses', $payload);
            } catch (ConnectionException $e) {
                throw new SupervisionException('Connexion OpenAI impossible.', 0, $e);
            }

            if ($response->successful()) {
                break;
            }

            if ($response->status() === 429 && $attempt < self::MAX_RETRIES - 1) {
                $attempt++;
                $delay = self::RETRY_DELAY_MS * (2 ** $attempt);
                usleep($delay * 1000);

                continue;
            }

            throw new SupervisionException(
                sprintf(
                    'Réponse OpenAI invalide (HTTP %d). %s',
                    $response->status(),
                    $response->status() === 429
                        ? 'Taux de requêtes dépassé. Essayez un modèle plus rapide (GPT-4o Mini) ou réessayez plus tard.'
                        : ''
                )
            );
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        if ($response === null || $response->failed()) {
            throw new SupervisionException(
                'Réponse OpenAI invalide après plusieurs tentatives (HTTP 429). Réessayez plus tard ou passez à GPT-4o Mini.'
            );
        }

        $body = $response->json();

        $text = $this->extractOutputText($body);
        $parsed = JsonResponseParser::parseSupervisionResult($text);

        // L'API Responses expose bien `usage.input_tokens` / `usage.output_tokens`
        // (contrairement à `chat/completions`). Un bloc `usage` absent reste
        // NON OBSERVÉ : on ne fabrique pas un usage de 0.
        $usage = AiUsage::of(
            self::readTokenCounter($body, 'usage.input_tokens'),
            self::readTokenCounter($body, 'usage.output_tokens'),
        );

        $resolvedModel = (string) data_get($body, 'model', $this->model);
        $cost = AiPricingCatalog::cost('openai', $resolvedModel, $usage);

        $inputTokens = $usage->inputTokensOrZero();
        $outputTokens = $usage->outputTokensOrZero();

        return new AiSupervisionResult(
            summary: $parsed['summary'],
            riskLevel: $parsed['risk_level'],
            category: $parsed['category'],
            skills: $parsed['skills'],
            unmatchedTerms: $parsed['unmatched_terms'],
            needsHumanCategoryReview: $parsed['needs_human_category_review'],
            categoryReviewReason: $parsed['category_review_reason'],
            recommendations: $parsed['recommendations'],
            moderationFlag: $parsed['moderation_flag'],
            notes: $parsed['notes'],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            model: $resolvedModel,
            estimatedCostUsd: $cost->costUsd,
            latencyMs: $latencyMs,
            costUnknown: $cost->costUnknown,
        );
    }

    public function runScenario(AiScenarioDefinition $scenario, string $content, ?string $model = null): array
    {
        if ($this->apiKey === '') {
            throw new SupervisionException('Clé API OpenAI manquante.');
        }

        $payload = [
            'model' => $model ?: $this->model,
            'max_output_tokens' => $this->maxOutputTokens,
            'store' => false,
            'input' => [
                ['role' => 'system', 'content' => $scenario->systemPrompt()],
                ['role' => 'user', 'content' => $content],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $scenario->id(),
                    'strict' => true,
                    'schema' => $scenario->jsonSchema(),
                ],
            ],
        ];

        $attempt = 0;
        $response = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->asJson()
                    ->post(rtrim($this->baseUrl, '/').'/responses', $payload);
            } catch (ConnectionException $e) {
                throw new SupervisionException('Connexion OpenAI impossible.', 0, $e);
            }

            if ($response->successful()) {
                break;
            }

            if ($response->status() === 429 && $attempt < self::MAX_RETRIES - 1) {
                $attempt++;
                $delay = self::RETRY_DELAY_MS * (2 ** $attempt);
                usleep($delay * 1000);

                continue;
            }

            throw new SupervisionException(
                sprintf(
                    'Réponse OpenAI invalide (HTTP %d). %s',
                    $response->status(),
                    $response->status() === 429
                        ? 'Taux de requêtes dépassé. Essayez un modèle plus rapide (GPT-4o Mini) ou réessayez plus tard.'
                        : ''
                )
            );
        }

        if ($response === null || $response->failed()) {
            throw new SupervisionException(
                'Réponse OpenAI invalide après plusieurs tentatives (HTTP 429). Réessayez plus tard ou passez à GPT-4o Mini.'
            );
        }

        $body = $response->json();
        $text = $this->extractOutputText($body);

        $jsonText = JsonResponseParser::extractJsonFromText($text);
        $parsed = json_decode($jsonText, true);

        if (! is_array($parsed)) {
            throw new SupervisionException('Sortie JSON OpenAI non décodable pour le scénario '.$scenario->id().'.');
        }

        return $parsed;
    }

    private function buildPayload(string $content, string $model): array
    {
        return [
            'model' => $model,
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

    private function loadTaxonomyFromDb(): array
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

    private function buildSystemPrompt(): string
    {
        $taxonomy = $this->loadTaxonomyFromDb();
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

        return self::BASE_SYSTEM_PROMPT
            ."\n\nTaxonomie officielle des catégories (utilise UNIQUEMENT ces slugs exacts) :\n"
            .$categoryLines
            ."\n\nCompétences secondaires disponibles (enrichissement uniquement, liste non exhaustive) :\n"
            .$skillLines;
    }

    private function jsonSchema(): array
    {
        $taxonomy = $this->loadTaxonomyFromDb();

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

    /**
     * Compteur de tokens réellement rapporté, ou null s'il est absent.
     *
     * Remplace `estimateCost()` (TASK-1132) : le calcul appartient désormais au
     * catalogue tarifaire, ce provider n'a plus à connaître de prix. Ne pas
     * retomber sur 0 est essentiel — c'est ce repli qui transformait une réponse
     * sans usage en coût de 0 apparemment mesuré.
     */
    private static function readTokenCounter(mixed $body, string $path): ?int
    {
        $value = data_get($body, $path);

        return is_numeric($value) ? (int) $value : null;
    }
}
