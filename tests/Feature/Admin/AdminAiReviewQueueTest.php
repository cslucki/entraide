<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiInteraction;
use App\Models\User;
use Tests\TestCase;

class AdminAiReviewQueueTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function createFlaggedInteraction(array $overrides = []): AdminAiInteraction
    {
        return AdminAiInteraction::create(array_merge([
            'scenario_id' => 'supervision_content',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_excerpt' => 'Contenu problématique test',
            'input_hash' => hash('sha256', 'test'),
            'input_length' => 10,
            'result_summary' => 'Flagged content summary',
            'result_payload' => [
                'moderation_flag' => true,
                'risk_level' => 'medium',
                'needs_human_category_review' => false,
            ],
            'input_tokens' => 50,
            'output_tokens' => 30,
            'latency_ms' => 500,
            'cost_usd' => 0.001,
        ], $overrides));
    }

    public function test_admin_can_access_review_queue(): void
    {
        $admin = $this->makeAdmin();
        $this->createFlaggedInteraction();

        $response = $this->actingAs($admin)->get(route('admin.ai-review-queue'));

        $response->assertOk();
        $response->assertSee('File de modération IA');
        $response->assertSee('Contenu problématique test');
    }

    public function test_empty_state_when_no_flagged_items(): void
    {
        $admin = $this->makeAdmin();

        AdminAiInteraction::create([
            'scenario_id' => 'clarify_help_request',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'success',
            'input_excerpt' => 'Safe content',
            'input_hash' => hash('sha256', 'safe'),
            'input_length' => 11,
            'result_summary' => 'Safe summary',
            'result_payload' => [
                'moderation_flag' => false,
                'risk_level' => 'low',
                'needs_human_category_review' => false,
            ],
            'input_tokens' => 30,
            'output_tokens' => 20,
            'latency_ms' => 300,
            'cost_usd' => 0.0005,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.ai-review-queue'));

        $response->assertOk();
        $response->assertSee('Aucun contenu en attente de modération');
    }

    public function test_non_admin_cannot_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ai-review-queue'))->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $response = $this->get(route('admin.ai-review-queue'));

        $response->assertRedirect(route('login'));
    }

    public function test_admin_can_approve_item(): void
    {
        $admin = $this->makeAdmin();
        $interaction = $this->createFlaggedInteraction();

        $response = $this->actingAs($admin)->patch(route('admin.ai-review-queue.update', $interaction->id), [
            'review_status' => 'approved',
        ]);

        $response->assertRedirect(route('admin.ai-review-queue'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'id' => $interaction->id,
            'review_status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        $this->assertNotNull($interaction->fresh()->reviewed_at);
    }

    public function test_admin_can_reject_item_with_notes(): void
    {
        $admin = $this->makeAdmin();
        $interaction = $this->createFlaggedInteraction();

        $response = $this->actingAs($admin)->patch(route('admin.ai-review-queue.update', $interaction->id), [
            'review_status' => 'rejected',
            'review_notes' => 'Ce contenu est inapproprié',
        ]);

        $response->assertRedirect(route('admin.ai-review-queue'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('admin_ai_interactions', [
            'id' => $interaction->id,
            'review_status' => 'rejected',
            'review_notes' => 'Ce contenu est inapproprié',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_sidebar_link_is_present(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.ai-review-queue'));

        $response->assertOk();
        $response->assertSee('File modération');
    }
}
