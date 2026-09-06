<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TASK-1341 — Smart Dossier V1.
 *
 * Architecture compressee : la capability, le process, l'AdminAiPrompt,
 * l'Agent et le DTO sont ceux de `loop_knowledge_answer` (TASK-1213), le
 * corpus vient de `DossierSemanticSearchService::representativeChunksAcrossDossiers()`
 * (double ici, comme TASK1213KnowledgeAnswerTest). Provider mocke, aucun
 * appel reseau reel (`Http::preventStrayRequests()`).
 */
class TASK1341SmartDossierInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $stranger;

    private Dossier $dossier;

    public function test_a_user_without_view_access_is_forbidden(): void
    {
        $this->mockSearch()->shouldNotReceive('representativeChunksAcrossDossiers');

        $this->actingAs($this->stranger)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertForbidden();
    }

    public function test_a_dossier_from_another_organization_is_not_found(): void
    {
        $otherDossier = Dossier::create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => User::factory()->create(['organization_id' => $this->otherOrganization->id])->id,
            'name' => 'Etranger',
            'visibility' => 'organization',
        ]);

        $this->mockSearch()->shouldNotReceive('representativeChunksAcrossDossiers');

        $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $otherDossier))
            ->assertNotFound();
    }

    public function test_a_non_member_of_the_governing_loop_is_refused(): void
    {
        $loop = (new LoopService)->createLoop($this->owner, 'Boucle Insights');
        $loopDossier = Dossier::where('loop_id', $loop->id)->firstOrFail();
        $outsider = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->mockSearch()->shouldNotReceive('representativeChunksAcrossDossiers');

        $this->actingAs($outsider)
            ->postJson($this->url($this->organization, $loopDossier))
            ->assertForbidden();
    }

    public function test_an_empty_corpus_triggers_no_provider_call_and_no_interaction(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->once()
            ->with($this->organization->id, [$this->dossier->id], 1)
            ->andReturn([]);

        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertStatus(422)
            ->assertExactJson(['code' => 'dossier_insights_no_content']);

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, AiInteraction::count());
    }

    public function test_get_on_the_dossier_page_never_triggers_a_generation(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->with($this->organization->id, [$this->dossier->id], 1)
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->organization, 'dossier' => $this->dossier]))
            ->assertOk();

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, AiInteraction::count());
    }

    public function test_provider_not_configured_returns_503(): void
    {
        OrganizationAiSetting::query()->delete();

        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertStatus(503)
            ->assertExactJson(['code' => 'dossier_insights_unavailable']);

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertSame(0, AiInteraction::count());
    }

    public function test_organization_budget_reached_returns_429_with_offers_url(): void
    {
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 0]);

        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A')]);

        $this->fakeAgent('ne doit jamais etre appele');

        $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertStatus(429)
            ->assertJsonStructure(['code', 'message', 'offers_url']);

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
    }

    public function test_a_grounded_answer_keeps_facts_and_records_the_trace(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A'), $this->row('B')]);

        $this->fakeAgent(<<<'MD'
        ## Synthèse
        Ces documents décrivent un protocole commun.

        ## Faits saillants
        - Le protocole prévoit une étape de validation [S1].
        - Un second document précise le calendrier [S2].

        ## Questions possibles
        - Qui valide la dernière étape ?
        MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertOk()
            ->assertJsonStructure(['html']);

        $html = $response->json('html');
        $this->assertStringContainsString('protocole prévoit une étape', $html);
        $this->assertStringContainsString('data-sources-kind="used"', $html);
        $this->assertStringNotContainsString('chunk_id', $html);
        $this->assertStringNotContainsString('distance', $html);

        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('loop_knowledge_answer', $interaction->feature);
        $this->assertSame($this->dossier->id, $interaction->metadata['dossier_id']);
        $this->assertStringNotContainsString('sk-test-1341', json_encode($interaction->toArray()));
    }

    public function test_an_invented_reference_is_stripped_from_the_answer(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A')]);

        $this->fakeAgent(<<<'MD'
        ## Faits saillants
        - Un fait réel [S1].
        - Un fait inventé [S9].
        MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertOk();

        $html = $response->json('html');
        $this->assertStringContainsString('Un fait réel', $html);
        $this->assertStringNotContainsString('[S9]', $html);
        $this->assertStringNotContainsString('Un fait inventé', $html);
    }

    public function test_a_convergence_backed_by_a_single_document_is_discarded(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A'), $this->row('B')]);

        $this->fakeAgent(<<<'MD'
        ## Faits saillants
        - Un fait réel et sourcé [S1].

        ## Convergences
        - Cette idée est soutenue une seule fois [S1].
        MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertOk();

        $html = $response->json('html');
        $this->assertStringNotContainsString('Cette idée est soutenue', $html);
    }

    public function test_two_references_of_the_same_document_are_not_a_convergence(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A'), $this->row('B')]);

        // S1 et S2 pointent tous deux vers le MEME document ('A') : le
        // service doit refuser cette convergence malgre deux refs distinctes.
        $this->fakeAgent(<<<'MD'
        ## Faits saillants
        - Un fait réel et sourcé [S1].

        ## Convergences
        - Deux extraits du même document [S1][S1].
        MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertOk();

        $html = $response->json('html');
        $this->assertStringNotContainsString('Deux extraits du même document', $html);
    }

    public function test_a_convergence_backed_by_two_distinct_documents_is_kept(): void
    {
        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->andReturn([$this->row('A'), $this->row('B')]);

        $this->fakeAgent(<<<'MD'
        ## Convergences
        - Les deux documents évoquent le même protocole [S1][S2].
        MD);

        $response = $this->actingAs($this->owner)
            ->postJson($this->url($this->organization, $this->dossier))
            ->assertOk();

        $html = $response->json('html');
        $this->assertStringContainsString('évoquent le même protocole', $html);
    }

    public function test_the_governing_loop_member_can_generate_an_insight_on_a_loop_dossier(): void
    {
        $loop = (new LoopService)->createLoop($this->owner, 'Boucle Insights membre');
        $loopDossier = Dossier::where('loop_id', $loop->id)->firstOrFail();
        $member = User::factory()->create(['organization_id' => $this->organization->id]);
        (new LoopService)->addMemberByUserId($loop, $member->id);

        $this->mockSearch()
            ->shouldReceive('representativeChunksAcrossDossiers')
            ->with($this->organization->id, [$loopDossier->id], \Mockery::any())
            ->andReturn([$this->row('A', $loopDossier)]);

        $this->fakeAgent('## Faits saillants'."\n".'- fait sourcé [S1].');

        $this->actingAs($member)
            ->postJson($this->url($this->organization, $loopDossier))
            ->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier Insights',
            'visibility' => 'private',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1341',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    private function mockSearch(): MockInterface
    {
        return $this->mock(DossierSemanticSearchService::class);
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * Forme rendue par `DossierSemanticSearchService::representativeChunksAcrossDossiers()`
     * — un chunk representatif par DOCUMENT, `distance` toujours null.
     *
     * @return array<string, mixed>
     */
    private function row(string $label, ?Dossier $dossier = null): array
    {
        $dossier ??= $this->dossier;

        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'mime_type' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label} : un protocole prévoit une étape de validation.",
            'distance' => null,
        ];
    }

    private function url(Organization $organization, Dossier $dossier): string
    {
        return route('organization.dossiers.insights', ['organization' => $organization, 'dossier' => $dossier]);
    }
}
