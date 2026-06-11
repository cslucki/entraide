<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiInteraction;
use App\Models\User;
use Tests\TestCase;

class AdminAiBenchmarkTest extends TestCase
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
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 1200,
            'cost_usd' => 0.0015,
        ], $overrides));
    }

    public function test_admin_can_access_benchmark_page(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction();

        $response = $this->actingAs($admin)->get(route('admin.ai-benchmark'));

        $response->assertOk();
        $response->assertSee('Benchmark IA');
        $response->assertSee('0,0015');
    }

    public function test_benchmark_shows_empty_state(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-benchmark'));

        $response->assertOk();
        $response->assertSee('Aucune interaction IA enregistrée');
        $response->assertSee(route('admin.ai-supervision'));
    }

    public function test_non_admin_cannot_access_benchmark_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ai-benchmark'))->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('admin.ai-benchmark'));

        $response->assertRedirect(route('login'));
    }

    public function test_sidebar_link_is_present(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-benchmark'));

        $response->assertOk();
        $response->assertSee('Benchmark IA');
    }

    public function test_benchmark_shows_cost_by_provider(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['provider' => 'openai', 'cost_usd' => 0.0020, 'input_tokens' => 100, 'output_tokens' => 50, 'latency_ms' => 800]);
        $this->makeInteraction(['provider' => 'ollama', 'cost_usd' => 0.0, 'input_tokens' => 200, 'output_tokens' => 100, 'latency_ms' => 2500]);

        $response = $this->actingAs($admin)->get(route('admin.ai-benchmark'));

        $response->assertOk();
        $response->assertSee('openai');
        $response->assertSee('ollama');
    }

    public function test_benchmark_shows_cost_by_scenario(): void
    {
        $admin = $this->makeAdmin();
        $this->makeInteraction(['scenario_id' => 'supervision_content', 'cost_usd' => 0.0015]);
        $this->makeInteraction(['scenario_id' => 'clarify_help_request', 'cost_usd' => 0.0025]);

        $response = $this->actingAs($admin)->get(route('admin.ai-benchmark'));

        $response->assertOk();
        $response->assertSee('supervision_content');
        $response->assertSee('clarify_help_request');
    }
}
