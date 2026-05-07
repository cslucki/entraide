<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\AI\AISettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAITest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_can_access_ai_settings()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ai'));
        $response->assertStatus(200);
        $response->assertSee('AI Orchestration');
    }

    public function test_non_admin_cannot_access_ai_settings()
    {
        $user = User::factory()->create(['is_admin' => false]);
        $response = $this->actingAs($user)->get(route('admin.ai'));
        $response->assertStatus(403);
    }

    public function test_admin_can_update_ai_settings()
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai.update'), [
            'ai_provider' => 'openai',
            'ai_openai_model' => 'gpt-4',
            'ai_master_prompt' => 'New Master Prompt',
            'ai_classification_prompt' => 'New Classification Prompt',
            'ai_examples_json' => json_encode([['input' => 'test', 'output' => 'test']]),
            'ai_enabled' => '1',
        ]);

        $response->assertRedirect(route('admin.ai'));
        $this->assertEquals('openai', \App\Models\Setting::get('ai_provider'));
        $this->assertEquals('New Master Prompt', \App\Models\Setting::get('ai_master_prompt'));
    }

    public function test_fake_provider_logic()
    {
        $service = app(\App\Services\AI\AIIntentService::class);

        $result = $service->classify('I want to teach English');

        $this->assertEquals('service_offer', $result['intent']);
        $this->assertEquals('languages', $result['category']);
        $this->assertEquals('fake', $result['provider']);
    }
}
