<?php

namespace App\Services\AI\Providers;

interface AIProviderInterface
{
    /**
     * Send a prompt to the AI provider and get a structured response.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @return array
     */
    public function complete(string $systemPrompt, string $userPrompt): array;
}
