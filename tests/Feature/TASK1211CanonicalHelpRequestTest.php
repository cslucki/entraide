<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMarketplaceLink;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

class TASK1211CanonicalHelpRequestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Category $category;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->user, 'Entraide locale');

        app()->instance('current_organization', $this->organization);

        AiConfig::set('clarification_enabled', true);
        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'test-key',
        ]);

        Http::preventStrayRequests();
    }

    public function test_loop_flow_only_prefills_the_canonical_form(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('organization.loops.help-request.continue', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]),
            [
                'title' => 'Relire mon dossier européen',
                'need' => 'Je cherche une relecture attentive avant le dépôt de mon dossier.',
                'relay_loop_id' => $this->loop->id,
            ],
        );

        $response->assertRedirect(route('organization.requests.create', $this->organization->slug));
        $response->assertSessionHasInput('title', 'Relire mon dossier européen');
        $response->assertSessionHasInput('description', 'Je cherche une relecture attentive avant le dépôt de mon dossier.');
        $response->assertSessionHasInput('relay_loop_id', $this->loop->id);

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_loop_flow_accepts_no_relay_destination(): void
    {
        $response = $this->actingAs($this->user)->post(
            route('organization.loops.help-request.continue', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]),
            [
                'title' => 'Structurer une demande claire',
                'need' => 'Je veux poursuivre dans le formulaire sans diffuser dans une Boucle.',
                'relay_loop_id' => '',
            ],
        );

        $response->assertRedirect(route('organization.requests.create', $this->organization->slug));
        $response->assertSessionMissing('relay_loop_id');
        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_human_submit_without_relay_creates_one_request_and_no_projection(): void
    {
        $this->actingAs($this->user)
            ->post($this->storeRoute(), $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('loop_messages', 0);
        $this->assertDatabaseCount('loop_marketplace_links', 0);
        $this->assertSame($this->organization->id, ServiceRequest::firstOrFail()->organization_id);
    }

    public function test_human_submit_with_an_authorized_relay_creates_one_linked_projection(): void
    {
        $this->actingAs($this->user)
            ->post($this->storeRoute(), $this->validPayload(['relay_loop_id' => $this->loop->id]))
            ->assertRedirect(route('organization.loops.show', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]));

        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('loop_messages', 1);
        $this->assertSame(0, LoopMarketplaceLink::query()->count());

        $request = ServiceRequest::firstOrFail();
        $message = LoopMessage::firstOrFail();

        $this->assertSame('help_request', $message->type);
        $this->assertSame($this->loop->id, $message->loop_id);
        $this->assertSame($request->id, $message->metadata['service_request_id']);
        $this->assertSame('service_request', $message->metadata['projection_type']);
        $this->assertTrue($message->isServiceRequestProjection());
        $this->assertArrayNotHasKey('description', $message->metadata);
    }

    public function test_invalid_relay_values_never_create_a_request_or_projection(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $otherLoop = (new LoopService)->createLoop($otherUser, 'Autre tenant');
        $nonMemberOwner = User::factory()->create(['organization_id' => $this->organization->id]);
        $nonMemberLoop = (new LoopService)->createLoop($nonMemberOwner, 'Sans adhésion');
        $archivedLoop = (new LoopService)->createLoop($this->user, 'Archivée');
        $archivedLoop->update(['status' => 'archived']);

        foreach (['pas-un-uuid', $otherLoop->id, $nonMemberLoop->id, $archivedLoop->id] as $relayLoopId) {
            $this->actingAs($this->user)
                ->from(route('organization.requests.create', $this->organization->slug))
                ->post($this->storeRoute(), $this->validPayload(['relay_loop_id' => $relayLoopId]))
                ->assertSessionHasErrors('relay_loop_id');
        }

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }

    public function test_foreign_and_non_uuid_categories_are_rejected_before_creation(): void
    {
        $otherOrganization = Organization::factory()->create();
        $foreignCategory = Category::factory()->create(['organization_id' => $otherOrganization->id]);

        foreach (['pas-un-uuid', $foreignCategory->id] as $categoryId) {
            $this->actingAs($this->user)
                ->post($this->storeRoute(), $this->validPayload(['category_id' => $categoryId]))
                ->assertSessionHasErrors('category_id');
        }

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_organization_slug_tampering_is_refused(): void
    {
        $otherOrganization = Organization::factory()->create();

        $this->actingAs($this->user)
            ->post(route('organization.requests.store', $otherOrganization->slug), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_requests_create_ai_assistance_proposes_only_title_and_description(): void
    {
        $this->fakeClarifier();

        $this->actingAs($this->user)
            ->get(route('organization.requests.create', $this->organization->slug))
            ->assertOk()
            ->assertSee('data-request-ai-formulation', false)
            ->assertSee(__('ai.request_formulate_cta'));

        $response = $this->actingAs($this->user)->postJson(
            route('organization.requests.ai-formulate', $this->organization->slug),
            ['description' => 'J’ai besoin de faire relire un dossier européen avant son dépôt.'],
        );

        $response->assertOk()
            ->assertJsonPath('suggestion.title', 'Faire relire mon dossier européen')
            ->assertJsonPath('suggestion.description', 'Je cherche une relecture structurée de mon dossier européen avant son dépôt.')
            ->assertJsonMissingPath('suggestion.category_id')
            ->assertJsonMissingPath('suggestion.relay_loop_id');

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
        Http::assertNothingSent();
    }

    public function test_projection_card_reads_the_canonical_request_and_handles_closed_or_missing(): void
    {
        $request = ServiceRequest::create($this->validPayload([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'status' => 'open',
        ]));
        app(LoopMessageService::class)->sendServiceRequestProjection($this->loop, $this->user, $request);

        $request->update([
            'title' => 'Titre canonique mis à jour',
            'description' => 'Description canonique actualisée après la création de la projection.',
            'status' => 'closed',
        ]);

        $detailUrl = route('organization.requests.show', [
            'organization' => $this->organization->slug,
            'request' => $request,
        ]);

        $this->actingAs($this->user)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee('Titre canonique mis à jour')
            ->assertSee(__('requests.status_closed'))
            ->assertSee($detailUrl, false);

        $request->delete();

        $this->actingAs($this->user)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('requests.projection_unavailable'))
            ->assertDontSee($detailUrl, false);
    }

    public function test_cross_tenant_request_detail_is_not_visible(): void
    {
        $request = ServiceRequest::create($this->validPayload([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'status' => 'open',
        ]));
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($otherUser)
            ->get(route('organization.requests.show', [$otherOrganization->slug, $request]))
            ->assertNotFound();
    }

    public function test_projection_cards_batch_load_canonical_requests(): void
    {
        foreach (['Première demande canonique', 'Deuxième demande canonique'] as $title) {
            $request = ServiceRequest::create($this->validPayload([
                'user_id' => $this->user->id,
                'organization_id' => $this->organization->id,
                'title' => $title,
                'status' => 'open',
            ]));
            app(LoopMessageService::class)->sendServiceRequestProjection($this->loop, $this->user, $request);
        }

        $canonicalLoads = 0;
        DB::listen(function ($query) use (&$canonicalLoads): void {
            if (str_contains(strtolower($query->sql), 'from "service_requests"')
                && str_contains(strtolower($query->sql), ' in (')) {
                $canonicalLoads++;
            }
        });

        $this->actingAs($this->user);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Première demande canonique')
            ->assertSee('Deuxième demande canonique');

        $this->assertSame(1, $canonicalLoads);
    }

    public function test_service_request_projection_never_dispatches_an_agent_response(): void
    {
        $profile = MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
        ]);
        $this->loop->forceFill([
            'type' => 'ai_agent',
            'member_ai_profile_id' => $profile->id,
        ])->save();
        $request = ServiceRequest::create($this->validPayload([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'status' => 'open',
        ]));

        Queue::fake();

        app(LoopMessageService::class)->sendServiceRequestProjection($this->loop, $this->user, $request);
        Queue::assertNotPushed(GenerateAiAgentResponse::class);

        app(LoopMessageService::class)->sendUserMessage($this->loop, $this->user, 'Un message utilisateur normal.');
        Queue::assertPushed(GenerateAiAgentResponse::class, 1);
    }

    private function storeRoute(): string
    {
        return route('organization.requests.store', $this->organization->slug);
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Relire mon dossier européen',
            'description' => str_repeat('Je cherche une relecture attentive et structurée avant le dépôt. ', 2),
            'category_id' => $this->category->id,
            'delivery_mode' => 'remote',
            'budget_min' => 10,
            'budget_max' => 20,
            'deadline' => null,
        ], $overrides);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Faire relire mon dossier européen',
            'clarified_request' => 'Je cherche une relecture structurée de mon dossier européen avant son dépôt.',
            'help_type' => 'review',
            'suggested_loop_id' => $this->loop->id,
            'suggestion_reason' => 'Cette Boucle réunit les membres concernés.',
            'questions_for_user' => [],
            'confidence' => 0.95,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage(10, 5),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }
}
