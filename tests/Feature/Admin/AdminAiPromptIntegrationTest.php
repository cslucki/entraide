<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiPrompt;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Services\Ai\Scenarios\ClarifyHelpRequestScenario;
use App\Services\Ai\Scenarios\SupervisionContentScenario;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

        $scenario = app(SupervisionContentScenario::class);
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

        $scenario = app(ClarifyHelpRequestScenario::class);
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

        $scenario = app(SupervisionContentScenario::class);
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

        $scenario = app(ClarifyHelpRequestScenario::class);
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

        $scenario = app(SupervisionContentScenario::class);
        $prompt = $scenario->systemPrompt();

        $this->assertStringContainsString('BASE PROMPT', $prompt);
        $this->assertStringContainsString('Taxonomie officielle', $prompt);
        $this->assertStringContainsString('Compétences secondaires', $prompt);
    }

    public function test_member_profile_responder_uses_visitor_chat_db_prompt_when_requested(): void
    {
        app()->setLocale('en');

        AdminAiPrompt::create([
            'scenario_id' => 'profile_agent_visitor_chat',
            'name' => 'Visitor prompt',
            'prompt_text' => 'DB VISITOR PROMPT UNIQUE',
            'version' => 99,
            'is_active' => true,
        ]);

        $organization = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'member_profile_summary' => 'Profil test utilisé par le responder.',
        ]);

        Http::fake([
            '*/api/generate' => Http::response(['response' => 'Réponse visiteur'], 200),
        ]);

        app(MemberProfileAgentResponder::class)
            ->answerWithProvider($profile, 'Bonjour', 'ollama', 'test-model', 'profile_agent_visitor_chat');

        Http::assertSent(fn (Request $request) => str_contains((string) $request['prompt'], 'DB VISITOR PROMPT UNIQUE')
            && ! str_contains((string) $request['prompt'], 'Objectif : aider le visiteur')
            && str_contains((string) $request['prompt'], 'current_locale=en')
            && str_contains((string) $request['prompt'], 'response_language=English')
            && str_contains((string) $request['prompt'], 'Respond in English when current_locale is en.')
            && str_contains((string) $request['prompt'], 'Réponds en français quand current_locale est fr.')
            && str_contains((string) $request['prompt'], 'published profile does not specify this competency')
            && str_contains((string) $request['prompt'], 'do not say that the member cannot or does not offer it')
            && str_contains((string) $request['prompt'], 'Profil test utilisé par le responder.'));
    }

    public function test_member_profile_responder_injects_french_locale_context_for_visitor_chat(): void
    {
        app()->setLocale('fr');

        $organization = Organization::factory()->create(['ai_profiles_enabled' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'member_profile_summary' => 'Profil test en français.',
        ]);

        Http::fake([
            '*/api/generate' => Http::response(['response' => 'Réponse visiteur'], 200),
        ]);

        app(MemberProfileAgentResponder::class)
            ->answerWithProvider($profile, 'Bonjour', 'ollama', 'test-model', 'profile_agent_visitor_chat');

        Http::assertSent(fn (Request $request) => str_contains((string) $request['prompt'], 'current_locale=fr')
            && str_contains((string) $request['prompt'], 'response_language=French')
            && str_contains((string) $request['prompt'], 'Réponds en français quand current_locale est fr.'));
    }
}
