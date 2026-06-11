<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiPrompt;
use App\Models\User;
use Tests\TestCase;

class AdminAiPromptIntegrationTest extends TestCase
{
    public function test_supervision_content_scenario_uses_db_prompt_when_available(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'supervision_content',
            'name' => 'Test supervision prompt',
            'prompt_text' => 'DB PROMPT: supervision content',
            'version' => 1,
            'is_active' => true,
        ]);

        $scenario = app(\App\Services\Ai\Scenarios\SupervisionContentScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('DB PROMPT: supervision content', $prompt);
    }

    public function test_clarify_help_request_scenario_uses_db_prompt_when_available(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'clarify_help_request',
            'name' => 'Test clarify prompt',
            'prompt_text' => 'DB PROMPT: clarify help request',
            'version' => 1,
            'is_active' => true,
        ]);

        $scenario = app(\App\Services\Ai\Scenarios\ClarifyHelpRequestScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('DB PROMPT: clarify help request', $prompt);
    }

    public function test_inactive_db_prompt_is_not_used(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'supervision_content',
            'name' => 'Inactive prompt',
            'prompt_text' => 'SHOULD NOT APPEAR',
            'version' => 1,
            'is_active' => false,
        ]);

        $scenario = app(\App\Services\Ai\Scenarios\SupervisionContentScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('supervision', $prompt);
        $this->assertStringNotContainsString('SHOULD NOT APPEAR', $prompt);
    }

    public function test_active_db_prompt_takes_precedence_over_hardcoded(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'clarify_help_request',
            'name' => 'Override',
            'prompt_text' => 'DB OVERRIDE',
            'version' => 2,
            'is_active' => true,
        ]);

        $scenario = app(\App\Services\Ai\Scenarios\ClarifyHelpRequestScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('DB OVERRIDE', $prompt);
        $this->assertStringNotContainsString('assistant d\'aide à la formulation', $prompt);
    }

    public function test_supervision_content_still_injects_taxonomy_with_db_prompt(): void
    {
        AdminAiPrompt::create([
            'scenario_id' => 'supervision_content',
            'name' => 'Taxonomy test',
            'prompt_text' => 'BASE PROMPT',
            'version' => 1,
            'is_active' => true,
        ]);

        $scenario = app(\App\Services\Ai\Scenarios\SupervisionContentScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('BASE PROMPT', $prompt);
        $this->assertStringContainsString('Taxonomie officielle', $prompt);
        $this->assertStringContainsString('Compétences secondaires', $prompt);
    }
}
