<?php

namespace Tests\Feature\Admin;

use App\Models\AiConfig;
use App\Models\User;
use App\Services\Ai\SupervisionProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    public function test_admin_can_access_config_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ai-config'));

        $response->assertOk();
        $response->assertSee('Réglages IA');
        $response->assertSee('AI Configuration');
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('admin.ai-config'));

        $response->assertRedirectToRoute('login');
    }

    public function test_non_admin_cannot_access_config_page(): void
    {
        $this->actingAs($this->user)->get(route('admin.ai-config'))->assertForbidden();
    }

    public function test_admin_can_update_default_provider(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai-config.update'), [
            'default_provider' => 'openai',
        ]);

        $response->assertRedirectToRoute('admin.ai-config');
        $response->assertSessionHas('success');

        $this->assertEquals('openai', AiConfig::get('default_provider'));
    }

    public function test_admin_can_update_model(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai-config.update'), [
            'default_model' => 'gpt-4o',
        ]);

        $response->assertRedirectToRoute('admin.ai-config');

        $this->assertEquals('gpt-4o', AiConfig::get('default_model'));
    }

    public function test_provider_is_validated_against_allowed_values(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai-config.update'), [
            'default_provider' => 'invalid-provider',
        ]);

        $response->assertSessionHasErrors('default_provider');
    }

    public function test_model_is_optional(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.ai-config.update'), [
            'default_model' => '',
        ]);

        $response->assertRedirectToRoute('admin.ai-config');
    }

    public function test_sidebar_link_is_present(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ai-config'));

        $response->assertSee('Réglages IA');
    }

    public function test_config_override_is_applied_at_runtime(): void
    {
        AiConfig::set('default_provider', 'openai');
        AiConfig::set('default_model', 'gpt-4o-mini');

        config(['ai.ollama.enabled' => true]);
        config(['ai.openai.supervision_enabled' => true]);
        config(['ai.default_provider' => 'openai']);

        $resolver = app(SupervisionProviderResolver::class);

        $this->assertEquals('openai', $resolver->defaultProvider());
    }
}
