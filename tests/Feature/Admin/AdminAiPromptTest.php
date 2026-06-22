<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiPrompt;
use App\Models\User;
use Database\Seeders\AiPromptSeeder;
use Tests\TestCase;

class AdminAiPromptTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makePrompt(array $overrides = []): AdminAiPrompt
    {
        return AdminAiPrompt::create(array_merge([
            'scenario_id' => 'supervision_content',
            'name' => 'Test prompt',
            'prompt_text' => 'Tu es un assistant de test.',
            'version' => 1,
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_can_list_prompts(): void
    {
        $this->seed(AiPromptSeeder::class);
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-prompts'));

        $response->assertOk();
        $response->assertSee('Supervision de contenu — v1');
        $response->assertSee('clarify_help_request');
    }

    public function test_admin_can_create_prompt(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(route('admin.ai-prompts.store'), [
            'scenario_id' => 'supervision_content',
            'name' => 'Nouveau prompt test',
            'description' => 'Description test',
            'prompt_text' => 'Tu es un assistant de test pour la création.',
        ]);

        $response->assertRedirect(route('admin.ai-prompts'));
        $this->assertDatabaseHas('admin_ai_prompts', [
            'name' => 'Nouveau prompt test',
            'scenario_id' => 'supervision_content',
        ]);
    }

    public function test_admin_can_view_prompt(): void
    {
        $admin = $this->makeAdmin();
        $prompt = $this->makePrompt(['prompt_text' => 'Contenu unique du prompt']);

        $response = $this->actingAs($admin)->get(route('admin.ai-prompts.show', $prompt));

        $response->assertOk();
        $response->assertSee('Contenu unique du prompt');
        $response->assertSee($prompt->name);
    }

    public function test_admin_can_edit_prompt(): void
    {
        $admin = $this->makeAdmin();
        $prompt = $this->makePrompt();

        $response = $this->actingAs($admin)->put(route('admin.ai-prompts.update', $prompt), [
            'name' => 'Prompt modifié',
            'prompt_text' => 'Tu es un assistant modifié.',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.ai-prompts'));
        $this->assertDatabaseHas('admin_ai_prompts', [
            'id' => $prompt->id,
            'name' => 'Prompt modifié',
        ]);
    }

    public function test_admin_can_delete_prompt(): void
    {
        $admin = $this->makeAdmin();
        $prompt = $this->makePrompt();

        $response = $this->actingAs($admin)->delete(route('admin.ai-prompts.destroy', $prompt));

        $response->assertRedirect(route('admin.ai-prompts'));
        $this->assertDatabaseMissing('admin_ai_prompts', ['id' => $prompt->id]);
    }

    public function test_scenario_filter_works(): void
    {
        $admin = $this->makeAdmin();
        $this->makePrompt(['scenario_id' => 'supervision_content', 'name' => 'Supervision-only']);
        $this->makePrompt(['scenario_id' => 'clarify_help_request', 'name' => 'Clarify-only']);

        $response = $this->actingAs($admin)->get(route('admin.ai-prompts', ['scenario_id' => 'clarify_help_request']));

        $response->assertOk();
        $response->assertSee('Clarify-only');
        $response->assertDontSee('Supervision-only');
    }

    public function test_create_auto_increments_version(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.ai-prompts.store'), [
            'scenario_id' => 'supervision_content',
            'name' => 'Version 1',
            'prompt_text' => 'Premier prompt.',
        ]);

        $this->actingAs($admin)->post(route('admin.ai-prompts.store'), [
            'scenario_id' => 'supervision_content',
            'name' => 'Version 2',
            'prompt_text' => 'Deuxième prompt.',
        ]);

        $v1 = AdminAiPrompt::where('scenario_id', 'supervision_content')->where('version', 1)->first();
        $v2 = AdminAiPrompt::where('scenario_id', 'supervision_content')->where('version', 2)->first();

        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertEquals('Version 1', $v1->name);
        $this->assertEquals('Version 2', $v2->name);
    }

    public function test_guest_cannot_access(): void
    {
        $response = $this->get(route('admin.ai-prompts'));
        $response->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ai-prompts'))->assertForbidden();
    }
}
