<?php

namespace App\Services\Ai\DTO;

final class AiSupervisionResult
{
    /**
     * @param  array{slug: string, label: string}  $category
     * @param  array<int, array{slug: string, label: string}>  $skills
     * @param  array<int, string>  $unmatchedTerms
     * @param  array<int, string>  $recommendations
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $riskLevel,
        public readonly array $category,
        public readonly array $skills,
        public readonly array $unmatchedTerms,
        public readonly bool $needsHumanCategoryReview,
        public readonly string $categoryReviewReason,
        public readonly array $recommendations,
        public readonly bool $moderationFlag,
        public readonly string $notes,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
        public readonly float $estimatedCostUsd,
        public readonly int $latencyMs,
    ) {}

    public function isHighRisk(): bool
    {
        return $this->riskLevel === 'high';
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'risk_level' => $this->riskLevel,
            'category' => $this->category,
            'skills' => $this->skills,
            'unmatched_terms' => $this->unmatchedTerms,
            'needs_human_category_review' => $this->needsHumanCategoryReview,
            'category_review_reason' => $this->categoryReviewReason,
            'recommendations' => $this->recommendations,
            'moderation_flag' => $this->moderationFlag,
            'notes' => $this->notes,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'model' => $this->model,
            'estimated_cost_usd' => $this->estimatedCostUsd,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
