<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
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
use Tests\TestCase;

/**
 * TASK-1297 — persistance unifiée des réponses IA dans le ChatLoop.
 *
 * Le chemin knowledge (`loop_knowledge_answer`) publie désormais l'échange
 * comme `ChatLoopAiService::ask()` : la question du membre (type `user`) puis
 * la réponse documentaire liée (type `ai`, `reply_to_id`, sources en
 * metadata). Trois propriétés sont prouvées ici en plus de la forme :
 *  - RIEN n'est écrit quand rien n'a coûté (pas de sources → pas d'appel
 *    modèle → pas d'interaction → pas de message) ;
 *  - un échec provider ne publie JAMAIS de message, mais laisse sa trace
 *    `failed` — la preuve qu'il s'est bien passé quelque chose ;
 *  - la publication de la question est réversible en UNE ligne de config
 *    (`ai.knowledge.publish_question`), consigne de gouvernance en attente
 *    de l'arbitrage produit (remontée Cyril du 24/08).
 */
class TASK1297UnifiedAiPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $secondMember;

    private User $stranger;

    private Loop $loop;

    private Dossier $visibleDossier;

    private FakeKnowledgeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->secondMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $loopService = new LoopService;
        $this->loop = $loopService->createLoop($this->member, 'Boucle persistance');
        $loopService->addMember($this->loop, $this->secondMember, 'member');

        app()->instance('current_organization', $this->organization);

        $this->visibleDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-tenant',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new FakeKnowledgeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    public function test_a_knowledge_answer_survives_a_page_refresh_for_every_loop_member(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Réponse documentaire persistée [S1].');

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Que contient le dossier de la Boucle ?'])
            ->assertOk();

        // Un AUTRE membre recharge la page : l'échange est là, question et
        // réponse — plus rien ne disparaît au rafraîchissement.
        $page = $this->actingAs($this->secondMember)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]));

        $page->assertOk()
            ->assertSee('Réponse documentaire persistée', false)
            ->assertSee('Que contient le dossier de la Boucle ?', false);
    }

    public function test_an_ungrounded_but_billed_answer_is_persisted_with_its_consulted_sources(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Réponse sans citation valable [S9].');

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Une question documentaire ?'])
            ->assertOk()
            ->assertJsonPath('grounded', false);

        // Une vraie réponse facturée (interaction réelle) se persiste, même
        // sans citation validée : on persiste ce qui a coûté.
        $aiMessage = LoopMessage::query()->where('type', 'ai')->firstOrFail();
        $this->assertFalse($aiMessage->metadata['grounded']);
        $this->assertSame(['S1'], array_column($aiMessage->metadata['sources'], 'ref'));
        $this->assertSame(AiInteraction::firstOrFail()->id, $aiMessage->metadata['ai_interaction_id']);
    }

    public function test_the_no_sources_answer_writes_nothing_and_calls_no_model(): void
    {
        $this->search->rows = [];
        $this->fakeAgent('ne doit pas être appelé');

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Question sans source ?'])
            ->assertOk()
            ->assertJsonPath('grounded', false);

        // Rien n'a coûté (pas d'appel modèle, pas d'interaction) : rien n'est
        // publié — ni la réponse « rien trouvé », ni la question.
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_interactions', 0);
        $this->assertSame(0, LoopMessage::count());
    }

    public function test_a_provider_failure_publishes_nothing_but_leaves_a_failed_trace(): void
    {
        $this->search->rows = [$this->row('A')];
        LoopKnowledgeAgent::fake(function (): TextResponse {
            throw new \RuntimeException('provider down');
        });

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Une question documentaire ?'])
            ->assertStatus(422);

        // Aucun message publié — mais la trace `failed` EXISTE : la preuve
        // qu'un appel a eu lieu et a échoué, pas qu'il ne s'est rien passé.
        $this->assertSame(0, LoopMessage::count());
        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('failed', $interaction->metadata['status']);
        $this->assertNull($interaction->response);
    }

    public function test_an_empty_ai_response_publishes_nothing(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('');

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Une question documentaire ?'])
            ->assertStatus(422);

        $this->assertSame(0, LoopMessage::count());
    }

    public function test_the_question_publication_is_reversible_in_one_line(): void
    {
        // La ligne de réversibilité (gouvernance 24/08) : si Cyril arbitre
        // « ne publie pas la question », ce flag suffit — la réponse seule
        // est publiée, non liée, la question restant en metadata.
        config(['ai.knowledge.publish_question' => false]);
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('Réponse publiée seule [S1].');

        $this->actingAs($this->member)
            ->postJson($this->knowledgeRoute(), ['question' => 'Une question discrète ?'])
            ->assertOk();

        $this->assertSame(1, LoopMessage::count());
        $aiMessage = LoopMessage::query()->where('type', 'ai')->firstOrFail();
        $this->assertNull($aiMessage->reply_to_id);
        $this->assertSame('Une question discrète ?', $aiMessage->metadata['question']);
    }

    public function test_a_foreign_tenant_is_refused_before_any_call_and_nothing_is_written(): void
    {
        $this->search->rows = [$this->row('A')];
        $this->fakeAgent('x');
        $route = $this->knowledgeRoute();

        app()->forgetInstance('current_organization');
        $this->assertContains(
            $this->actingAs($this->stranger)->postJson($route, ['question' => 'question ?'])->getStatusCode(),
            [403, 404],
        );

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertNull($this->search->lastCall);
        $this->assertSame(0, LoopMessage::count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function knowledgeRoute(): string
    {
        return route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $label): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $this->visibleDossier->id,
            'dossier_name' => $this->visibleDossier->name,
            'source_type' => 'article',
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article '.$label,
            'slug' => 'article-'.strtolower($label),
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label}.",
            'distance' => 0.2,
        ];
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }
}

/**
 * Double du moteur pgvector, même contrat que celui de TASK1213 (redéclaré ici
 * pour qu'une exécution ciblée de ce seul fichier reste autonome).
 */
class FakeKnowledgeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit', 'traceMetadata', 'candidateLimit');

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
