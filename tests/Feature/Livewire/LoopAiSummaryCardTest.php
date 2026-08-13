<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopAiSummaryCard;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LoopAiSummaryCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->service = new LoopService;
        $this->loop = $this->service->createLoop($this->member, 'Test Chat Loop');

        app()->instance('current_organization', $this->organization);

        config(['ai.openai.api_key' => 'test-key']);
        config(['ai.chatloop.min_summary_words' => 0]);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Synthèse IA de la Boucle.']]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
        ]);
    }

    public function test_member_sees_empty_state_when_no_summary(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('hasSummary', false)
            ->assertSet('canGenerate', true)
            ->assertSee(__('loops.cards.ai_summary.empty_title'));
    }

    public function test_member_can_generate_a_summary(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->call('generate')
            ->assertSet('hasSummary', true)
            ->assertSee('Synthèse IA de la Boucle.');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);

        $summary = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->first();
        $this->assertSame('summarize', $summary->metadata['action']);
        $this->assertSame($this->member->id, $summary->metadata['requested_by']);
    }

    public function test_existing_summary_is_displayed_on_mount(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Résumé déjà présent.',
            'type' => 'ai',
            'metadata' => ['action' => 'summarize', 'requested_by' => $this->member->id],
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('hasSummary', true)
            ->assertSee('Résumé déjà présent.');
    }

    public function test_monthly_budget_refusal_uses_the_existing_non_blocking_error_surface(): void
    {
        AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'chatloop.summarize',
            'feature' => 'chatloop_ai_summarize',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'prompt',
            'response' => 'response',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => 2.00,
            'cost_unknown' => false,
        ]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->call('generate')
            ->assertSet('hasSummary', false)
            ->assertSet('errorMessage', __('loops.ai_summary_monthly_budget_reached'))
            ->assertSee(__('loops.ai_summary_monthly_budget_reached'));

        Http::assertNothingSent();
    }

    public function test_non_member_cannot_generate(): void
    {
        $this->actingAs($this->nonMember);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('canGenerate', false)
            ->call('generate')
            ->assertSet('hasSummary', false);

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);
    }

    public function test_cross_organization_user_cannot_generate(): void
    {
        $this->actingAs($this->crossUser);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('canGenerate', false)
            ->call('generate')
            ->assertSet('hasSummary', false);

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);
    }

    public function test_deactivated_member_cannot_generate(): void
    {
        $this->member->update(['banned_at' => now()]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('canGenerate', false)
            ->call('generate')
            ->assertSet('hasSummary', false);
    }
}
