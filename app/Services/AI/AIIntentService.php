<?php

namespace App\Services\AI;

class AIIntentService
{
    public function __construct(
        protected AIProviderFactory $factory,
        protected AISettingsService $settings
    ) {
    }

    /**
     * Classify user intent from a raw prompt using configured AI settings.
     *
     * @param string $prompt
     * @return array
     */
    public function classify(string $prompt): array
    {
        if (!$this->settings->isAIEnabled()) {
            return [
                'intent' => 'search',
                'category' => 'other',
                'confidence' => 1.0,
                'disabled' => true
            ];
        }

        $providerInstance = $this->factory->make();
        $providerName = $this->settings->getActiveProvider();
        $systemPrompt = $this->settings->getFullSystemPrompt();

        $result = $providerInstance->complete($systemPrompt, $prompt);

        $this->logInteraction($prompt, $providerName, $result);

        return $result;
    }

    /**
     * Log the AI interaction for debugging and pattern analysis.
     */
    protected function logInteraction(string $input, string $provider, array $result): void
    {
        try {
            \App\Models\AIInteractionLog::create([
                'user_id'           => auth()->id(),
                'community_id'      => app()->bound('current_community') ? app('current_community')?->id : null,
                'provider'          => $provider,
                'user_input'        => $input,
                'detected_intent'   => $result['intent'] ?? null,
                'detected_category' => $result['category'] ?? null,
                'confidence_score'  => $result['confidence'] ?? null,
                'raw_response'      => $result,
                'is_debug'          => $this->settings->isDebugMode(),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to log AI interaction', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
