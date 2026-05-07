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

        $provider = $this->factory->make();
        $systemPrompt = $this->settings->getFullSystemPrompt();

        return $provider->complete($systemPrompt, $prompt);
    }
}
