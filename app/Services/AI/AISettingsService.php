<?php

namespace App\Services\AI;

use App\Models\AIPrompt;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

class AISettingsService
{
    public const PROVIDER_FAKE = 'fake';
    public const PROVIDER_OPENAI = 'openai';

    public function getActiveProvider(): string
    {
        return Setting::get('ai_provider', self::PROVIDER_FAKE);
    }

    public function getOpenAIModel(): string
    {
        return Setting::get('ai_openai_model', 'gpt-4o-mini');
    }

    public function isAIEnabled(): bool
    {
        $val = Setting::get('ai_enabled', '1');
        return $val === '1' || $val === true;
    }

    public function isDebugMode(): bool
    {
        return Setting::get('ai_debug_mode', '0') === '1';
    }

    public function getMasterPrompt(): string
    {
        $prompt = AIPrompt::getLatest('master');
        if ($prompt) return $prompt->content;

        return "You are an intent classification agent for Entraide, a peer-to-peer service exchange platform.\nYour goal is to classify the user's input into one of the following intents to redirect them to the correct page.";
    }

    public function getClassificationPrompt(): string
    {
        $prompt = AIPrompt::getLatest('classification');
        if ($prompt) return $prompt->content;

        return "Intents:\n- 'service_offer': The user wants to provide a service, help others, or list their skills.\n- 'service_request': The user is looking for help, needs a specific service, or has a problem to solve.\n- 'search': The user wants to explore available services or members without a specific immediate need.\n- 'profile': The user wants to complete their profile, settings, or onboarding.\n- 'unknown': The intent is not clear or doesn't fit the above.\n\nCategories: 'it', 'home', 'languages', 'education', 'legal', 'health', 'other'.\n\nOutput must be a JSON object:\n{\n    \"intent\": \"service_offer\" | \"service_request\" | \"search\" | \"profile\" | \"unknown\",\n    \"category\": \"it\" | \"home\" | \"languages\" | \"education\" | \"legal\" | \"health\" | \"other\",\n    \"confidence\": float (0.0 to 1.0)\n}";
    }

    public function getFewShotExamples(): array
    {
        $prompt = AIPrompt::getLatest('examples');
        $content = $prompt ? $prompt->content : json_encode([
            ['input' => 'I want to help people with Excel', 'output' => ['intent' => 'service_offer', 'category' => 'it', 'confidence' => 1.0]],
            ['input' => 'I need a plumber', 'output' => ['intent' => 'service_request', 'category' => 'home', 'confidence' => 1.0]],
            ['input' => 'I want to learn English', 'output' => ['intent' => 'service_request', 'category' => 'languages', 'confidence' => 1.0]],
        ]);

        return json_decode($content, true) ?? [];
    }

    public function updatePrompt(string $type, string $content): AIPrompt
    {
        $latest = AIPrompt::getLatest($type);
        $version = $latest ? $latest->version + 1 : 1;

        return AIPrompt::create([
            'type' => $type,
            'content' => $content,
            'version' => $version,
            'created_by' => Auth::id(),
        ]);
    }

    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (in_array($key, ['ai_master_prompt', 'ai_classification_prompt', 'ai_examples_json'])) {
                $type = str_replace(['ai_', '_prompt', '_json'], '', $key);
                if ($type === 'examples') {
                     $this->updatePrompt('examples', $value);
                } else {
                     $this->updatePrompt($type, $value);
                }
                continue;
            }
            Setting::set($key, $value);
        }
    }

    public function getFullSystemPrompt(): string
    {
        $prompt = $this->getMasterPrompt() . "\n\n" . $this->getClassificationPrompt();

        $examples = $this->getFewShotExamples();
        if (!empty($examples)) {
            $prompt .= "\n\nExamples:\n";
            foreach ($examples as $example) {
                $prompt .= "Input: " . $example['input'] . "\nOutput: " . json_encode($example['output']) . "\n";
            }
        }

        return $prompt;
    }
}
