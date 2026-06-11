<?php

namespace Tests\Unit\Services\Ai\Persistence;

use App\Models\User;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiInteractionPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function persistence(): AdminAiInteractionPersistence
    {
        return app(AdminAiInteractionPersistence::class);
    }

    public function test_provided_input_hash_is_preserved_not_re_hashed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $providedHash = 'abc123def456789';

        $this->persistence()->persist([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'input_excerpt' => 'some content',
            'input_hash' => $providedHash,
        ]);

        $this->assertDatabaseHas('admin_ai_interactions', [
            'input_hash' => $providedHash,
        ]);
    }

    public function test_input_hash_fallback_uses_input_excerpt_when_content_is_missing(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $excerpt = 'Je fais une demande de devis pour un logo.';

        $this->persistence()->persist([
            'scenario_id' => 'supervision_content',
            'provider' => 'ollama',
            'input_excerpt' => $excerpt,
        ]);

        $expectedHash = hash('sha256', $excerpt);

        $this->assertDatabaseHas('admin_ai_interactions', [
            'input_hash' => $expectedHash,
        ]);
    }

    public function test_input_hash_fallback_uses_content_before_input_excerpt(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $content = 'full raw content here';
        $excerpt = 'different excerpt';

        $this->persistence()->persist([
            'scenario_id' => 'clarify_help_request',
            'provider' => 'openrouter',
            'content' => $content,
            'input_excerpt' => $excerpt,
        ]);

        $expectedHash = hash('sha256', $content);

        $this->assertDatabaseHas('admin_ai_interactions', [
            'input_hash' => $expectedHash,
        ]);
    }

    public function test_input_hash_is_null_when_no_input_available(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->persistence()->persist([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
        ]);

        $this->assertDatabaseHas('admin_ai_interactions', [
            'scenario_id' => 'supervision_content',
            'input_hash' => null,
        ]);
    }
}
