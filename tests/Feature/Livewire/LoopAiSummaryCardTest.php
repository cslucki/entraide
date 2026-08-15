<?php

namespace Tests\Feature\Livewire;

use App\Ai\Agents\LoopSummaryAgent;
use App\Livewire\LoopAiSummaryCard;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
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
        // TASK-1212 : l'IA transverse est configuree par Organization.
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->otherOrganization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);

        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->service = new LoopService;
        $this->loop = $this->service->createLoop($this->member, 'Test Chat Loop');

        app()->instance('current_organization', $this->organization);

        config(['ai.openai.api_key' => 'test-key']);
        config(['ai.providers.openai.driver' => 'openai']);
        config(['ai.providers.openai.key' => 'test-key']);
        config(['ai.chatloop.min_summary_words' => 0]);

        LoopSummaryAgent::fake(['Synthèse IA de la Boucle.']);

        Http::fake();
    }

    public function test_member_sees_empty_state_when_no_summary(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertSet('hasSummary', false)
            ->assertSet('canGenerate', true)
            ->assertSee(__('loops.cards.ai_summary.empty_title'));
    }

    /**
     * TASK-1207 : la surface visible est inchangee, mais le resume n'est plus
     * publie dans la Boucle — `loop_summary` est `can_write=false`.
     */
    public function test_member_can_generate_a_summary_without_publishing_it_in_the_loop(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->call('generate')
            ->assertSet('hasSummary', true)
            ->assertSee('Synthèse IA de la Boucle.');

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'type' => 'ai',
        ]);

        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('chatloop.summarize', $interaction->process);
        $this->assertSame($this->loop->id, $interaction->metadata['loop_id']);
        $this->assertSame($this->member->id, $interaction->metadata['requested_by']);
    }

    public function test_existing_summary_is_displayed_on_mount(): void
    {
        AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'chatloop.summarize',
            'feature' => 'chatloop_ai_summarize',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'contexte',
            'response' => 'Résumé déjà présent.',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cost_usd' => null,
            'cost_unknown' => true,
            'metadata' => ['loop_id' => $this->loop->id, 'requested_by' => $this->member->id],
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

        LoopSummaryAgent::assertNeverPrompted();
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
