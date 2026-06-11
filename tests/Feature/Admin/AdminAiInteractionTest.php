<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiInteraction;
use App\Models\User;
use Tests\TestCase;

class AdminAiInteractionTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeInteraction(array $overrides = []): AdminAiInteraction
    {
        return AdminAiInteraction::create(array_merge([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_excerpt' => 'Test excerpt',
            'input_hash' => hash('sha256', 'Test excerpt'),
            'input_length' => 12,
            'result_summary' => 'Test summary',
            'result_payload' => ['risk_level' => 'low'],
            'metadata' => ['test' => true],
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1200,
            'cost_usd' => 0.0015,
        ], $overrides));
    }

    public function test_admin_can_access_interactions_list(): void
    {
        $admin = $this->makeAdmin();
        $interaction = $this->makeInteraction();

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions'));

        $response->assertOk();
        $response->assertSee($interaction->scenario_id);
        $response->assertSee($interaction->provider);
    }

    public function test_non_admin_cannot_access_interactions_list(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ai-interactions'))->assertForbidden();
    }

    public function test_list_shows_pagination(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 0; $i < 30; $i++) {
            $this->makeInteraction(['input_excerpt' => "Excerpt {$i}"]);
        }

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions'));

        $response->assertOk();
        $response->assertSee('Excerpt 0');
        $response->assertSee('Excerpt 24');
        $response->assertDontSee('Excerpt 25');
        $response->assertSee('?page=2');
    }

    public function test_filter_by_provider(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['provider' => 'openai', 'input_excerpt' => 'OpenAI excerpt']);
        $this->makeInteraction(['provider' => 'ollama', 'input_excerpt' => 'Ollama excerpt']);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions', ['provider' => 'ollama']));

        $response->assertOk();
        $response->assertSee('Ollama excerpt');
        $response->assertDontSee('OpenAI excerpt');
    }

    public function test_filter_by_scenario_id(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['scenario_id' => 'supervision_content', 'input_excerpt' => 'Supervision-only-text']);
        $this->makeInteraction(['scenario_id' => 'clarify_help_request', 'input_excerpt' => 'Clarify-only-text']);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions', ['scenario_id' => 'clarify_help_request']));

        $response->assertOk();
        $response->assertSee('Clarify-only-text');
        $response->assertDontSee('Supervision-only-text');
    }

    public function test_filter_by_status(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['status' => 'success', 'input_excerpt' => 'Success-only-text']);
        $this->makeInteraction(['status' => 'error', 'input_excerpt' => 'Error-only-text']);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions', ['status' => 'error']));

        $response->assertOk();
        $response->assertSee('Error-only-text');
        $response->assertDontSee('Success-only-text');
    }

    public function test_filter_by_search(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['input_excerpt' => 'Je cherche un logo']);
        $this->makeInteraction(['input_excerpt' => 'Autre chose']);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions', ['search' => 'logo']));

        $response->assertOk();
        $response->assertSee('Je cherche un logo');
        $response->assertDontSee('Autre chose');
    }

    public function test_filter_date_fields_are_present(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions'));

        $response->assertOk();
        $response->assertSee('date_from');
        $response->assertSee('date_to');
    }

    public function test_filter_reset_link_works(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions', ['provider' => 'openai']));

        $response->assertOk();
        $response->assertSee(route('admin.ai-interactions'));
    }

    public function test_empty_state_message(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions'));

        $response->assertOk();
        $response->assertSee('Aucune interaction IA trouvée');
        $response->assertSee(route('admin.ai-supervision'));
    }

    public function test_admin_can_view_interaction_detail(): void
    {
        $admin = $this->makeAdmin();
        $interaction = $this->makeInteraction(['result_payload' => ['title' => 'Test']]);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions.show', $interaction));

        $response->assertOk();
        $response->assertSee($interaction->scenario_id);
        $response->assertSee('Test');
        $response->assertSee($interaction->provider);
    }

    public function test_detail_page_shows_json_payload(): void
    {
        $admin = $this->makeAdmin();
        $interaction = $this->makeInteraction(['result_payload' => ['risk_level' => 'low', 'confidence' => 0.9]]);

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions.show', $interaction));

        $response->assertOk();
        $response->assertSee('risk_level');
        $response->assertSee('confidence');
    }

    public function test_sidebar_link_is_present_and_active(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-interactions'));

        $response->assertOk();
        $response->assertSee('Historique IA');
    }
}
