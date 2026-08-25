<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1236 — tracabilite de la version de doctrine reellement appliquee.
 *
 * Dette du GAP LIST de TASK-1234 : la version de doctrine Organization
 * composee par `PromptRepository` (TASK-1227) n'etait pas tracee sur la ligne
 * `ai_interactions` qu'elle a servi a generer. Un audit a posteriori devait
 * la reconstituer depuis l'historique `organization_ai_doctrines` + une
 * phrase de cloture, jamais la lire directement sur l'interaction.
 *
 * Mecanisme : `PromptRepository::activeDoctrineVersion()` (meme resolution
 * que `compose()`, appelee a cote, sans toucher au contrat byte-identique de
 * `compose()` deja teste par TASK-1227) ; le resultat est trace en
 * `metadata.doctrine_version` sur `ai_interactions`, pour les capabilities
 * canoniques `loop_ask`/`loop_answer`, `loop_summary`, `clarify_help_request`
 * et `loop_knowledge_answer`. La cle est TOUJOURS presente, meme a `null` :
 * sa seule PRESENCE distingue une ligne tracee (ce mecanisme a tourne) d'une
 * ligne anterieure a TASK-1236, ce qu'un `array_filter` sur `null` effacerait.
 */
class TASK1236DoctrineVersionTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'monthly_budget_usd' => null,
        ]);

        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        $this->organization->update(['admin_id' => $this->admin->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle TASK-1236');
        $this->seedMessage($this->loop, $this->member, 'Bonjour, je prepare le lancement de mon activite. SENTINELLE-CONTEXTE-1236');

        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key-never-used',
            'ai.clarify.enabled' => true,
            'ai.chatloop.enabled' => true,
            'ai.chatloop.min_summary_words' => 0,
        ]);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Preuve requise : version N tracee, un changement de version ne touche
    // pas les interactions deja enregistrees.
    // =====================================================================

    public function test_a_doctrine_version_change_is_traced_on_new_interactions_without_touching_older_ones(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-V1', $this->admin);

        LoopDirectAnswerAgent::fake(['Reponse sous doctrine v1.']);
        $this->service()->ask($this->loop, $this->member, 'Premiere question ?');

        $first = AiInteraction::query()->latest('id')->first();
        $this->assertSame(1, $first->metadata['doctrine_version']);

        // Nouvelle version active : v2 remplace v1 pour l'Organization.
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-V2', $this->admin);

        LoopDirectAnswerAgent::fake(['Reponse sous doctrine v2.']);
        $this->service()->ask($this->loop, $this->member, 'Seconde question ?');

        $second = AiInteraction::query()->latest('id')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $second->metadata['doctrine_version']);

        // L'ancienne ligne n'est ni backfillee ni touchee par le changement.
        $first->refresh();
        $this->assertSame(1, $first->metadata['doctrine_version']);
    }

    public function test_without_an_active_doctrine_the_metadata_key_is_present_and_null(): void
    {
        $this->assertNull(OrganizationAiDoctrine::activeFor((string) $this->organization->id));

        LoopDirectAnswerAgent::fake(['Reponse sans doctrine.']);
        $this->service()->ask($this->loop, $this->member, 'Question sans doctrine ?');

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertArrayHasKey('doctrine_version', $interaction->metadata);
        $this->assertNull($interaction->metadata['doctrine_version']);
    }

    // =====================================================================
    // Couverture des quatre points d'enregistrement canoniques modifies.
    // =====================================================================

    public function test_doctrine_version_is_traced_on_loop_summary(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-SUMMARY', $this->admin);
        LoopSummaryAgent::fake([new TextResponse('Synthese.', new Usage(12, 6), new Meta('openai', 'gpt-4o-mini'))]);

        app()->setLocale('fr');
        app(ChatLoopAiService::class)->summarize($this->loop, $this->member);

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame(1, $interaction->metadata['doctrine_version']);
    }

    public function test_doctrine_version_is_traced_on_clarify_help_request(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-CLARIFY', $this->admin);
        $this->fakeClarifier();

        app(ClarifyUserHelpRequestService::class)->clarifyForOrganization($this->organization, $this->member, 'jai besoin daide');

        $interaction = AiInteraction::query()->latest('id')->first();
        $this->assertSame(1, $interaction->metadata['doctrine_version']);
    }

    public function test_doctrine_version_is_traced_on_loop_knowledge_answer(): void
    {
        OrganizationAiDoctrine::activate($this->organization, 'SENTINELLE-DOCTRINE-KNOWLEDGE', $this->admin);

        $search = new Task1236FakeSearch;
        // TASK-1294 : la reponse part de la Boucle — le Dossier consulte doit
        // appartenir a son perimetre, ici en lui etant partage.
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);
        $search->rows = [[
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article A',
            'slug' => 'article-a',
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'source_type' => 'article',
            'content' => 'Contenu pertinent pour la question.',
            'distance' => 0.2,
        ]];
        $this->app->instance(DossierSemanticSearchService::class, $search);

        config([
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
        ]);

        LoopKnowledgeAgent::fake([new TextResponse('Reponse [S1].', new Usage(20, 10), new Meta('openai', 'gpt-4o-mini'))]);

        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que contient la valise ?');

        $interaction = AiInteraction::query()->where('feature', 'loop_knowledge_answer')->latest('id')->first();
        $this->assertNotNull($interaction);
        $this->assertSame(1, $interaction->metadata['doctrine_version']);
    }

    private function service(): ChatLoopAiService
    {
        return app(ChatLoopAiService::class);
    }

    private function seedMessage(Loop $loop, User $sender, string $body): void
    {
        LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $loop->organization_id,
        ]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Cadrer nos usages de l’IA',
            'clarified_request' => 'Je cherche de l’aide pour cadrer nos usages de l’IA.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake([
            new StructuredTextResponse(
                $structured,
                json_encode($structured, JSON_UNESCAPED_UNICODE),
                new Usage(120, 80),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);
    }
}

class Task1236FakeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null): array
    {
        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
