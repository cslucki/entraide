<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\SupervisionProvider;
use App\Services\Ai\DTO\AiSupervisionResult;
use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\JsonResponseParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OllamaSupervisionProvider implements SupervisionProvider
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
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function supervise(string $content, ?string $model = null): AiSupervisionResult
    {
        if ($this->baseUrl === '') {
            throw new SupervisionException('Ollama non configuré.');
        }

        $payload = $this->buildPayload($content, $model ?? $this->model);

        $startedAt = (int) (microtime(true) * 1000);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/').'/api/generate', $payload);
        } catch (ConnectionException $e) {
            throw new SupervisionException('Connexion Ollama impossible.', 0, $e);
        }

        $latencyMs = (int) (microtime(true) * 1000) - $startedAt;

        if (! $response->successful()) {
            throw new SupervisionException(sprintf(
                'Réponse Ollama invalide (HTTP %d).', $response->status()
            ));
        }

        $body = $response->json();
        $rawResponse = $body['response'] ?? '';

        // Fallback: some models (e.g. qwen3.5 with thinking enabled) put the JSON in the "thinking" field
        if ($rawResponse === '' && isset($body['thinking']) && is_string($body['thinking']) && $body['thinking'] !== '') {
            $rawResponse = $body['thinking'];
        }

        $parsed = JsonResponseParser::parseSupervisionResult($rawResponse);

        $outputTokens = (int) ($body['eval_count'] ?? 0);

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
            inputTokens: 0,
            outputTokens: $outputTokens,
            model: (string) ($body['model'] ?? $this->model),
            estimatedCostUsd: 0.0,
            latencyMs: $latencyMs,
        );
    }

    private function buildPayload(string $content, string $model): array
    {
        return [
            'model' => $model,
            'prompt' => $this->buildPrompt($content),
            'stream' => false,
            'format' => 'json',
            'think' => false,
            'options' => [
                'num_predict' => 900,
                'temperature' => 0,
            ],
        ];
    }

    private function buildPrompt(string $content): string
    {
        return $this->buildSystemPrompt()."\n\n---\n\nContenu à analyser :\n".$content;
    }

    private function loadTaxonomyFromDb(): array
    {
        $categories = [];
        $skills = [];

        if (\Illuminate\Support\Facades\Schema::hasTable('categories')) {
            $categories = \App\Models\Category::select('slug', 'name_b2c as label')
                ->orderBy('slug')
                ->get()
                ->each(fn ($c) => $c->label = $c->label ?? '')
                ->toArray();
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('skills')) {
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
            .$skillLines
            ."\n\nFormat de réponse attendu (uniquement du JSON valide avec ces champs) :\n"
            .'{
  "summary": "résumé neutre",
  "risk_level": "low|medium|high",
  "category": {"slug": "...", "label": "..."},
  "skills": [{"slug": "...", "label": "..."}],
  "unmatched_terms": ["terme1", "terme2"],
  "needs_human_category_review": false,
  "category_review_reason": "",
  "recommendations": ["reco1", "reco2"],
  "moderation_flag": false,
  "notes": ""
}';
    }
}
