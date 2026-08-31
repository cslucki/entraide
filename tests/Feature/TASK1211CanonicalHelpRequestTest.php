<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\OrganizationCategoriesSource;
use App\Ai\ContexteIa;
use App\Jobs\GenerateAiAgentResponse;
use App\Livewire\LoopChat;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMarketplaceLink;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Loops\HelpRequestHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
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
        // TASK-1212 : l'IA transverse est configuree par Organization.
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openai', 'model' => 'gpt-4o-mini']);
        $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->user, 'Entraide locale');

        app()->instance('current_organization', $this->organization);

        AiConfig::set('clarification_enabled', true);
        // TASK-1350 : viser la ligne ACTIVE, et non la v2 par son numero — la
        // version active du scenario evolue (v3 depuis TASK-1350), alors que ce
        // que le test prouve — le prompt administrable est bien celui qui est
        // compose — ne depend d'aucun numero.
        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('is_active', true)
            ->update([
                'name' => 'Clarification de demande d’aide — test',
                'prompt_text' => 'MARQUEUR PROMPT ADMIN CLARIFY.',
                'is_active' => true,
            ]);
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

        $html = $this->actingAs($this->user)
            ->get($response->headers->get('Location'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Relire mon dossier européen"', $html);
        $this->assertStringContainsString('Je cherche une relecture attentive avant le dépôt de mon dossier.', $html);
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($this->loop->id, '/').'"[^>]*selected/',
            $html,
        );

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }

    /**
     * La categorie proposee par l'IA voyage avec le titre et la description
     * jusqu'au formulaire canonique, ou l'humain la garde ou la change. Une
     * categorie d'un autre tenant ne franchit jamais ce passage.
     */
    public function test_loop_flow_forwards_only_a_tenant_category_suggestion(): void
    {
        $foreign = Category::factory()->create();

        $continue = route('organization.loops.help-request.continue', [
            'organization' => $this->organization->slug,
            'loop' => $this->loop,
        ]);
        $payload = [
            'title' => 'Relire mon dossier européen',
            'need' => 'Je cherche une relecture attentive avant le dépôt de mon dossier.',
            'relay_loop_id' => '',
        ];

        $handoff = app(HelpRequestHandoff::class);

        $this->actingAs($this->user)
            ->post($continue, $payload + ['suggested_category_id' => $this->category->id])
            ->assertRedirect(route('organization.requests.create', $this->organization->slug));
        $this->assertSame($this->category->id, $handoff->pullDraft($this->user, $this->organization)['category_id'] ?? null);

        $this->actingAs($this->user)
            ->post($continue, $payload + ['suggested_category_id' => $foreign->id])
            ->assertRedirect(route('organization.requests.create', $this->organization->slug));
        $draft = $handoff->pullDraft($this->user, $this->organization);
        $this->assertSame('Relire mon dossier européen', $draft['title'] ?? null);
        $this->assertNull($draft['category_id'] ?? null);

        $this->actingAs($this->user)
            ->from(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]))
            ->post($continue, $payload + ['suggested_category_id' => 'not-a-uuid'])
            ->assertSessionHasErrors('suggested_category_id');

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
    }

    /**
     * Meme course que pour l'analyse : le clic « Continuer ma demande » quitte
     * une page qui poll toutes les 3 s. Le brouillon transfere au formulaire
     * canonique doit survivre a une requete Livewire servie entre le POST et
     * le GET de `requests/create`.
     */
    public function test_the_draft_reaches_the_canonical_form_despite_a_livewire_poll_in_between(): void
    {
        $post = $this->actingAs($this->user)->post(
            route('organization.loops.help-request.continue', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]),
            [
                'title' => 'Relire mon dossier européen',
                'need' => 'Je cherche une relecture attentive avant le dépôt de mon dossier.',
                'relay_loop_id' => $this->loop->id,
                'suggested_category_id' => $this->category->id,
            ],
        );
        $post->assertRedirect(route('organization.requests.create', $this->organization->slug));

        $snapshot = Livewire::actingAs($this->user)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->snapshot;

        $this->actingAs($this->user)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($snapshot),
                    'calls' => [['method' => '$refresh', 'params' => []]],
                    'updates' => [],
                ]],
            ])
            ->assertOk();

        $html = $this->actingAs($this->user)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="Relire mon dossier européen"', $html);
        $this->assertStringContainsString('Je cherche une relecture attentive avant le dépôt de mon dossier.', $html);
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($this->category->id, '/').'"[^>]*selected/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote($this->loop->id, '/').'"[^>]*selected/',
            $html,
        );
        $this->assertDatabaseCount('service_requests', 0);
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

        $draft = app(HelpRequestHandoff::class)->pullDraft($this->user, $this->organization);
        $this->assertSame('Structurer une demande claire', $draft['title'] ?? null);
        $this->assertNull($draft['relay_loop_id'] ?? null);
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
            ->assertJsonPath('suggestion.category_id', null)
            ->assertJsonMissingPath('suggestion.relay_loop_id');

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
        Http::assertNothingSent();
    }

    /**
     * L'etat Alpine du panneau est ecrit dans un attribut HTML : un guillemet
     * double glisse dans le script le tronque silencieusement et tout le
     * panneau meurt dans le navigateur (« loading is not defined »). Le DOM
     * doit recevoir l'expression entiere, jusqu'a sa derniere methode.
     */
    public function test_requests_create_ai_panel_state_reaches_the_browser_intact(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('organization.requests.create', $this->organization->slug))
            ->assertOk()
            ->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $panel = (new \DOMXPath($dom))->query('//*[@data-request-ai-formulation]')->item(0);
        $this->assertNotNull($panel, 'AI panel not rendered');

        $state = $panel->getAttribute('x-data');
        $this->assertStringContainsString('async formulate()', $state);
        $this->assertStringContainsString('applySuggestion()', $state);
        $this->assertStringContainsString('dismissSuggestion()', $state);
        $this->assertStringNotContainsString('"', $state, 'x-data must not contain a double quote: it ends the HTML attribute');
    }

    public function test_deterministic_fallback_never_presents_a_false_or_destructive_improvement(): void
    {
        config(['ai.clarify.enabled' => false]);

        $response = $this->actingAs($this->user)->postJson(
            route('organization.requests.ai-formulate', $this->organization->slug),
            [
                'title' => 'Demande d’aide',
                'description' => 'Je cherche de l’aide pour monter un dossier de financement européen.',
            ],
        );

        $response->assertUnprocessable()
            ->assertJsonStructure(['error'])
            ->assertJsonMissingPath('suggestion');

        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
        Http::assertNothingSent();
    }

    public function test_an_empty_ai_field_preserves_the_existing_user_value(): void
    {
        $this->fakeClarifier([
            'title' => 'Monter un dossier de financement européen',
            'clarified_request' => '',
        ]);

        $description = 'Je cherche de l’aide pour monter un dossier de financement européen.';
        $this->actingAs($this->user)->postJson(
            route('organization.requests.ai-formulate', $this->organization->slug),
            ['title' => 'Demande d’aide', 'description' => $description],
        )->assertOk()
            ->assertJsonPath('suggestion.title', 'Monter un dossier de financement européen')
            ->assertJsonPath('suggestion.description', $description);
    }

    public function test_a_category_offered_in_context_is_returned_for_human_selection(): void
    {
        $this->category->update([
            'name_b2c' => 'Financement européen',
            'name_b2b' => 'Montage de projets européens',
            'service_1' => 'Recherche de financements',
        ]);
        $this->fakeClarifier(['suggested_category_id' => $this->category->id]);

        $this->actingAs($this->user)->postJson(
            route('organization.requests.ai-formulate', $this->organization->slug),
            ['description' => 'Je cherche de l’aide pour monter un dossier de financement européen.'],
        )->assertOk()
            ->assertJsonPath('suggestion.category_id', $this->category->id)
            ->assertJsonPath('suggestion.category_label', 'Financement européen');

        HelpRequestClarifierAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString('MARQUEUR PROMPT ADMIN CLARIFY.', $instructions);
            $this->assertLessThan(strpos($instructions, 'Capability: clarify_help_request'), strpos($instructions, 'Constitution BouclePro IA'));
            $this->assertLessThan(strpos($instructions, 'MARQUEUR PROMPT ADMIN CLARIFY.'), strpos($instructions, 'Capability: clarify_help_request'));
            $this->assertStringContainsString((string) $this->category->id, (string) $prompt->prompt);

            return true;
        });
    }

    public function test_an_invented_or_cross_tenant_category_is_never_returned(): void
    {
        $foreignOrganization = Organization::factory()->create();
        $foreignCategory = Category::factory()->create(['organization_id' => $foreignOrganization->id]);

        foreach ([$foreignCategory->id, fake()->uuid()] as $categoryId) {
            $this->fakeClarifier(['suggested_category_id' => $categoryId]);

            $this->actingAs($this->user)->postJson(
                route('organization.requests.ai-formulate', $this->organization->slug),
                ['description' => 'Je cherche une catégorie adaptée pour ma demande.'],
            )->assertOk()->assertJsonPath('suggestion.category_id', null);
        }
    }

    public function test_categories_context_is_tenant_scoped_and_uses_the_organization_vocabulary(): void
    {
        $this->organization->update(['transactions_naming' => 'b2b']);
        $this->category->update([
            'name_b2c' => 'Nom particuliers',
            'name_b2b' => 'Nom professionnel',
            'service_1' => 'Montage de dossiers',
        ]);
        $foreign = Category::factory()->create();

        $fragment = app(OrganizationCategoriesSource::class)->collect(new ContexteIa(
            organizationId: (string) $this->organization->id,
            userId: (string) $this->user->id,
            loopId: null,
            locale: 'fr',
            capability: CapabilityRegistry::CLARIFY_HELP_REQUEST,
            correlationId: fake()->uuid(),
            source: CapabilityRegistry::SOURCE_ORGANIZATION_CATEGORIES,
        ), 4000);

        $this->assertStringContainsString((string) $this->category->id, $fragment->text);
        $this->assertStringContainsString('Nom professionnel', $fragment->text);
        $this->assertStringContainsString('Montage de dossiers', $fragment->text);
        $this->assertStringNotContainsString((string) $foreign->id, $fragment->text);
    }

    public function test_missing_active_admin_prompt_fails_explicitly_without_calling_a_provider(): void
    {
        AdminAiPrompt::query()->delete();
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('The SDK must not be called without an active DB prompt.');
        });

        $this->actingAs($this->user)->postJson(
            route('organization.requests.ai-formulate', $this->organization->slug),
            ['description' => 'Je cherche de l’aide pour monter un dossier européen.'],
        )->assertStatus(503)->assertJsonStructure(['error']);

        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_the_request_form_can_apply_a_valid_category_suggestion(): void
    {
        $this->actingAs($this->user)
            ->get(route('organization.requests.create', $this->organization->slug))
            ->assertOk()
            ->assertSee('suggestion.category_id', false)
            ->assertSee('suggestion?.category_label', false)
            ->assertSee((string) $this->category->id);
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

    /**
     * Recette Emergence du 2026-08-15 : la clarification IA reussit, la trace
     * est ecrite, mais l'ecran redirige revient vide, sans aucune erreur.
     *
     * La page d'une Boucle interroge le serveur toutes les 3 s (`wire:poll` de
     * ChatLoop). Un flash de session ne vit que jusqu'a la sauvegarde de la
     * requete SUIVANTE — et la suivante est ce poll, servi entre la redirection
     * et son GET. Ce test rejoue exactement cet ordre : POST -> requete
     * Livewire reelle (kernel + middleware de session) -> GET redirige.
     */
    public function test_the_clarified_request_survives_a_livewire_poll_served_before_the_redirected_screen(): void
    {
        $this->fakeClarifier(['suggested_category_id' => $this->category->id]);

        $post = $this->actingAs($this->user)->post(
            route('organization.loops.help-request.analyze', [
                'organization' => $this->organization->slug,
                'loop' => $this->loop,
            ]),
            ['intention' => 'Je cherche de l’aide pour monter un dossier de financement européen.'],
        );
        $post->assertRedirect();

        // Le poll de ChatLoop, servi AVANT l'ecran attendu : une vraie requete
        // Livewire, avec la pile de middleware (donc la session) du kernel.
        $snapshot = Livewire::actingAs($this->user)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->snapshot;

        $this->actingAs($this->user)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($snapshot),
                    'calls' => [['method' => '$refresh', 'params' => []]],
                    'updates' => [],
                ]],
            ])
            ->assertOk();

        $html = $this->actingAs($this->user)
            ->get($post->headers->get('Location'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Faire relire mon dossier européen', $html);
        $this->assertStringContainsString('name="relay_loop_id"', $html);
        $this->assertStringContainsString(__('loops.help_request_continue_cta'), $html);
        // La categorie suggeree part avec le reste vers le formulaire canonique.
        $this->assertMatchesRegularExpression(
            '/name="suggested_category_id"[^>]*value="'.preg_quote($this->category->id, '/').'"/',
            $html,
        );
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('loop_messages', 0);
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

    private function fakeClarifier(array $overrides = []): void
    {
        $structured = array_merge([
            'title' => 'Faire relire mon dossier européen',
            'clarified_request' => 'Je cherche une relecture structurée de mon dossier européen avant son dépôt.',
            'help_type' => 'review',
            'suggested_category_id' => '',
            'suggested_loop_id' => $this->loop->id,
            'suggestion_reason' => 'Cette Boucle réunit les membres concernés.',
            'questions_for_user' => [],
            'confidence' => 0.95,
            'needs_human_review' => false,
        ], $overrides);

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
