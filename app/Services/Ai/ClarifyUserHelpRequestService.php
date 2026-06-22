<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTO\AssistedInteractionLabResult;

class ClarifyUserHelpRequestService implements AiProvider
{
    public function __construct(
        private readonly SupervisionProviderResolver $resolver,
        private readonly AiScenarioFactory $scenarioFactory,
        private readonly FakeAIProvider $fallback,
    ) {}

    public function analyze(string $phrase): AssistedInteractionLabResult
    {
        if (! config('ai.clarify.enabled', false)) {
            return $this->fallback->analyze($phrase);
        }

        $scenario = $this->scenarioFactory->resolve('clarify_help_request');

        if (! $scenario) {
            return $this->fallback->analyze($phrase);
        }

        $providerName = $this->resolver->defaultProvider();

        if (! $providerName) {
            return $this->fallback->analyze($phrase);
        }

        $provider = $this->resolver->resolve($providerName);

        $result = $provider->runScenario($scenario, $phrase);

        return $this->mapToDto($result, $phrase);
    }

    private function mapToDto(array $result, string $originalPhrase = ''): AssistedInteractionLabResult
    {
        $confidence = (float) ($result['confidence'] ?? 0.0);
        $needsHumanReview = (bool) ($result['needs_human_review'] ?? true);
        $questions = $result['questions_for_user'] ?? [];

        $fallbackNeeded = $needsHumanReview || $confidence < 0.65 || ! empty($questions);

        $reason = null;

        if ($fallbackNeeded) {
            if ($needsHumanReview) {
                $reason = 'La demande nécessite une relecture humaine avant publication.';
            } elseif (! empty($questions)) {
                $reason = 'Des questions de clarification sont nécessaires pour préciser la demande.';
            } else {
                $reason = 'Confiance insuffisante pour générer un brouillon publiable.';
            }
        }

        $helpType = $result['help_type'] ?? 'other';

        return new AssistedInteractionLabResult(
            intent: $helpType === 'service_offer' ? 'offer' : 'help_request',
            confidence: $confidence,
            title: $result['title'] ?? 'Nouvelle demande',
            need: $result['clarified_request'] ?? ($result['publishable_draft'] ?? ''),
            context: '',
            expectedHelpType: $this->mapHelpType($helpType),
            deadline: ['has_deadline' => false, 'label' => null, 'date' => null],
            suggestedLoop: isset($result['suggested_loop']) && $result['suggested_loop'] !== ''
                ? ['id' => null, 'label' => $result['suggested_loop'], 'reason' => 'Suggéré par l\'analyse IA']
                : null,
            tone: [
                'label' => 'clair et structuré',
                'rationale' => 'Généré par clarification IA',
            ],
            messageDraft: $result['publishable_draft'] ?? ($result['clarified_request'] ?? null),
            fallback: [
                'needed' => $fallbackNeeded,
                'reason' => $reason,
                'questions' => $questions,
            ],
            humanValidation: [
                'required' => $fallbackNeeded,
                'primary_label' => $fallbackNeeded ? 'Modifier la demande' : 'Valider la preview',
                'secondary_label' => $fallbackNeeded ? 'Reformuler' : 'Modifier le brouillon',
            ],
            safety: [
                'contains_sensitive_data' => false,
                'needs_human_review' => $needsHumanReview,
                'blocked' => false,
            ],
            scenario: 'clarify_help_request',
            scenarioLabel: 'Clarification de demande d\'aide',
            originalPhrase: $originalPhrase,
        );
    }

    private function mapHelpType(string $helpType): string
    {
        return match ($helpType) {
            'service_offer' => 'proposition de service',
            'collaboration' => 'collaboration',
            'information' => 'information, conseil',
            'support' => 'soutien, accompagnement',
            default => 'autre',
        };
    }
}
