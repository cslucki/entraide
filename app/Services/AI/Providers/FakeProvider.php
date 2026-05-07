<?php

namespace App\Services\AI\Providers;

class FakeProvider implements AIProviderInterface
{
    public function complete(string $systemPrompt, string $userPrompt): array
    {
        $prompt = mb_strtolower($userPrompt);

        $intent = 'search';
        $category = 'other';
        $confidence = 0.85;

        // Simple rule-based logic for the fake provider
        if (str_contains($prompt, 'help') || str_contains($prompt, 'offer') || str_contains($prompt, 'teach') || str_contains($prompt, 'can do')) {
            $intent = 'service_offer';
        } elseif (str_contains($prompt, 'need') || str_contains($prompt, 'look') || str_contains($prompt, 'want') || str_contains($prompt, 'search')) {
            $intent = 'service_request';
        } elseif (str_contains($prompt, 'profile') || str_contains($prompt, 'setting') || str_contains($prompt, 'account')) {
            $intent = 'profile';
        }

        if (str_contains($prompt, 'excel') || str_contains($prompt, 'computer') || str_contains($prompt, 'it') || str_contains($prompt, 'code')) {
            $category = 'it';
        } elseif (str_contains($prompt, 'plumber') || str_contains($prompt, 'house') || str_contains($prompt, 'garden') || str_contains($prompt, 'clean')) {
            $category = 'home';
        } elseif (str_contains($prompt, 'english') || str_contains($prompt, 'french') || str_contains($prompt, 'spanish') || str_contains($prompt, 'teach')) {
            $category = 'languages';
        }

        return [
            'intent' => $intent,
            'category' => $category,
            'confidence' => $confidence,
            'provider' => 'fake'
        ];
    }
}
