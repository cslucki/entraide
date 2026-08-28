<?php

namespace App\Services\Ai\DTO;

/**
 * TASK-1321 : `$suggestedLoop`, quand non null, a la forme
 * `{id, label, provenance: {verified: list<{type, loop_id}>, ai_wording: {text, verified: false}|null}}`.
 * `provenance.verified` est un fait reconstruit cote serveur au point de
 * validation (jamais transmis tel quel) ; `provenance.ai_wording` est le
 * texte libre du modele, toujours marque `verified: false` — jamais presente
 * comme une preuve.
 */
class AssistedInteractionLabResult
{
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly string $title,
        public readonly string $need,
        public readonly string $context,
        public readonly string $expectedHelpType,
        public readonly array $deadline,
        public readonly ?array $suggestedLoop,
        public readonly array $tone,
        public readonly ?string $messageDraft,
        public readonly array $fallback,
        public readonly array $humanValidation,
        public readonly array $safety,
        public readonly string $scenario,
        public readonly string $scenarioLabel,
        public readonly string $originalPhrase = '',
        public readonly ?array $suggestedCategory = null,
        public readonly string $producer = 'unknown',
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'title' => $this->title,
            'need' => $this->need,
            'context' => $this->context,
            'expected_help_type' => $this->expectedHelpType,
            'deadline' => $this->deadline,
            'suggested_loop' => $this->suggestedLoop,
            'suggested_category' => $this->suggestedCategory,
            'tone' => $this->tone,
            'message_draft' => $this->messageDraft,
            'fallback' => $this->fallback,
            'human_validation' => $this->humanValidation,
            'safety' => $this->safety,
            '_scenario' => $this->scenario,
            '_scenario_label' => $this->scenarioLabel,
            'original_phrase' => $this->originalPhrase,
            '_producer' => $this->producer,
        ];
    }

    public function needsFallback(): bool
    {
        return $this->fallback['needed'] ?? false;
    }

    public function isBlocked(): bool
    {
        return $this->safety['blocked'] ?? false;
    }

    public function hasSensitiveData(): bool
    {
        return $this->safety['contains_sensitive_data'] ?? false;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.65;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < 0.65;
    }
}
