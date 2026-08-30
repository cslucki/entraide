<?php

namespace Tests\Feature\Livewire;

use App\Ai\Agents\LoopSummaryAgent;
use App\Livewire\LoopAiSummaryCard;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
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
            ->assertSeeHtml('data-loop-pulse')
            ->assertViewHas('pulse', [
                'members' => 1,
                'polls' => 0,
                'events' => 0,
            ])
            ->assertSee(__('loops.cards.ai_summary.empty_title'));

        LoopSummaryAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::count());
    }

    public function test_member_sees_exact_deterministic_loop_pulse_counts_and_canonical_ctas(): void
    {
        $this->setCardEnabled('core.roadmap');
        $this->setCardEnabled('core.decisions');

        $secondMember = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $secondMember->id,
        ]);
        LoopMember::factory()->invited()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->create(['organization_id' => $this->organization->id])->id,
        ]);

        LoopRoadmapItem::factory()->todo()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
        ]);
        LoopRoadmapItem::factory()->inProgress()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
        ]);
        LoopRoadmapItem::factory()->done()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
        ]);

        foreach (['Décision A', 'Décision B'] as $title) {
            LoopDecision::create([
                'organization_id' => $this->organization->id,
                'loop_id' => $this->loop->id,
                'author_id' => $this->member->id,
                'title' => $title,
                'decided_on' => today(),
            ]);
        }

        $this->createPoll(['question' => 'Sondage ouvert']);
        $this->createPoll([
            'question' => 'Sondage fermé',
            'status' => LoopPoll::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->createEvent([
            'title' => 'Événement futur',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $this->createEvent([
            'title' => 'Événement en cours',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $this->createEvent([
            'title' => 'Événement annulé',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
            'status' => LoopEvent::STATUS_CANCELLED,
        ]);
        $this->createEvent([
            'title' => 'Événement passé',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', [
                'members' => 2,
                'roadmap' => 2,
                'decisions' => 2,
                'polls' => 1,
                'events' => 2,
            ])
            ->assertSeeHtml('data-loop-pulse-target="core.members"')
            ->assertSeeHtml('data-loop-pulse-target="core.roadmap"')
            ->assertSeeHtml('data-loop-pulse-target="core.decisions"')
            ->assertSeeHtml('data-loop-pulse-target="core.polls"')
            ->assertSeeHtml('data-loop-pulse-target="core.events"')
            ->assertSeeHtml("\$dispatch('bp-open-loop-card', { card: 'core.roadmap' })");

        LoopSummaryAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::count());
    }

    public function test_inactive_card_removes_its_metric_count_and_cta(): void
    {
        $this->createEvent();
        $this->setCardEnabled('core.events', false);
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => ! array_key_exists('events', $pulse))
            ->assertDontSeeHtml('data-loop-pulse-count="events"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.events"');
    }

    public function test_active_card_without_view_permission_removes_its_metric_count_and_cta(): void
    {
        $this->createEvent();
        config(['loop_permissions.role_defaults.owner' => array_values(array_diff(
            config('loop_permissions.role_defaults.owner'),
            ['events.view'],
        ))]);
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => ! array_key_exists('events', $pulse))
            ->assertDontSeeHtml('data-loop-pulse-count="events"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.events"');
    }

    public function test_members_metric_requires_the_members_card_view_permission(): void
    {
        config(['loop_permissions.role_defaults.owner' => array_values(array_diff(
            config('loop_permissions.role_defaults.owner'),
            ['loop_members.view'],
        ))]);
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => ! array_key_exists('members', $pulse))
            ->assertDontSeeHtml('data-loop-pulse-count="members"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.members"');
    }

    public function test_open_poll_is_counted_exactly_in_loop_pulse(): void
    {
        $this->createPoll(['question' => 'Question ouverte']);
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => $pulse['polls'] === 1)
            ->assertSeeHtml('data-loop-pulse-target="core.polls"');
    }

    public function test_closed_poll_is_not_counted_in_loop_pulse(): void
    {
        $this->createPoll([
            'question' => 'Question close',
            'status' => LoopPoll::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => $pulse['polls'] === 0);
    }

    public function test_general_composition_shows_exactly_members_polls_and_events_metrics(): void
    {
        $this->createPoll();
        $this->createEvent();
        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', [
                'members' => 1,
                'polls' => 1,
                'events' => 1,
            ])
            ->assertSeeHtml('data-loop-pulse-target="core.members"')
            ->assertSeeHtml('data-loop-pulse-target="core.polls"')
            ->assertSeeHtml('data-loop-pulse-target="core.events"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.roadmap"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.decisions"')
            ->assertDontSeeHtml('data-loop-pulse-target="core.dossiers"');

        LoopSummaryAgent::assertNeverPrompted();
        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::count());
    }

    public function test_in_progress_event_counts_as_living_in_loop_pulse(): void
    {
        $this->createEvent([
            'title' => 'Réunion en cours',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => $pulse['events'] === 1);
    }

    public function test_cancelled_future_event_does_not_count_as_living_in_loop_pulse(): void
    {
        $this->createEvent([
            'title' => 'Réunion annulée',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => LoopEvent::STATUS_CANCELLED,
        ]);

        $this->actingAs($this->member);

        Livewire::test(LoopAiSummaryCard::class, ['loop' => $this->loop])
            ->assertViewHas('pulse', fn (array $pulse) => $pulse['events'] === 0);
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
        // TASK-1260 : jumelle ledger — l'autorite generation de la garde
        // depuis le cutover ; le reel ecrit les deux tables ensemble.
        AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->member->id,
            'process' => 'chatloop.summarize',
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => 2.00,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
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
            ->assertDontSeeHtml('data-loop-pulse')
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
            ->assertDontSeeHtml('data-loop-pulse')
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
            ->assertDontSeeHtml('data-loop-pulse')
            ->call('generate')
            ->assertSet('hasSummary', false);
    }

    /** @param array<string, mixed> $overrides */
    private function createEvent(array $overrides = []): LoopEvent
    {
        return LoopEvent::create(array_merge([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
            'title' => 'Événement',
            'format' => LoopEvent::FORMAT_ONLINE,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'timezone' => 'Europe/Paris',
            'meeting_url' => 'https://example.test/meeting',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
            'status' => LoopEvent::STATUS_SCHEDULED,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function createPoll(array $overrides = []): LoopPoll
    {
        return LoopPoll::create(array_merge([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
            'question' => 'Question',
            'selection_type' => LoopPoll::TYPE_SINGLE,
            'status' => LoopPoll::STATUS_OPEN,
        ], $overrides));
    }

    private function setCardEnabled(string $key, bool $enabled = true): void
    {
        LoopCard::updateOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => $key],
            [
                'organization_id' => $this->organization->id,
                'enabled' => $enabled,
                'added_by_preset' => $this->loop->type,
            ],
        );

        $this->loop->unsetRelation('cards');
    }
}
