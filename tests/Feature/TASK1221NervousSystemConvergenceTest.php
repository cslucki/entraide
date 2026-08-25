<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AdminAiPrompt;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\LoopService;
use App\Support\Ai\AiCorrelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1221 — convergence du systeme nerveux IA V1.
 *
 * Trois blocs de preuves :
 *
 *  A. GOUVERNANCE DU PROMPT `loop_summary` : le point canonique n'a plus le
 *     droit de retomber silencieusement sur un prompt hardcode — un prompt
 *     AdminAiPrompt actif est exige, et la migration de provisioning garantit
 *     qu'il existe (copie immuable du comportement par defaut historique).
 *  B. ACCEPTANCE DU PARCOURS DOCUMENTAIRE : ingestion reelle -> question ->
 *     reponse sourcee -> les invocations canoniques TASK-1220 correspondantes
 *     (une query embedding + une generation, MEME correlation metier).
 *  C. CONTRAT DU SYSTEME NERVEUX : ce que chaque capability declare
 *     (HITL, canWrite, sources autorisees) est fige en test — la matrice du
 *     TASK file a un garde-fou de regression.
 */
class TASK1221NervousSystemConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const PROVISION_MIGRATION = 'database/migrations/2026_08_17_190000_provision_chatloop_summarize_admin_ai_prompts.php';

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1221',
        ]);
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Boucle TASK1221');

        app()->instance('current_organization', $this->organization);

        AiConfig::set('default_provider', 'openai');
        AiConfig::set('default_model', 'gpt-4o-mini');

        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai.chatloop.min_summary_words' => 0,
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Gouvernance du prompt loop_summary
    // =====================================================================

    public function test_the_canonical_summary_requires_an_active_db_prompt(): void
    {
        // Un admin peut tout desactiver : le systeme doit le DIRE, pas
        // recuperer un prompt hardcode en silence.
        AdminAiPrompt::query()->where('scenario_id', 'like', 'chatloop_ai_summarize%')->delete();

        LoopSummaryAgent::fake(function (): never {
            throw new RuntimeException('The SDK must not be called without an active DB prompt.');
        });

        try {
            app(ChatLoopAiService::class)->summarize($this->loop, $this->owner);
            $this->fail('An explicit exception was expected when no active summarize prompt exists.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('loops.ai_summary_prompt_missing'), $exception->getMessage());
        }

        // Aucun appel provider : aucune trace P1, aucune invocation canonique.
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_the_provisioning_migration_installs_both_locales_active(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'like', 'chatloop_ai_summarize%')->delete();

        $this->provisionMigration()->up();

        foreach (['chatloop_ai_summarize_fr', 'chatloop_ai_summarize_en'] as $scenario) {
            $prompt = AdminAiPrompt::query()->where('scenario_id', $scenario)->firstOrFail();
            $this->assertTrue($prompt->is_active, "{$scenario} should be active");
            $this->assertSame(1, (int) $prompt->version);
            $this->assertTrue(Str::isUuid($prompt->id));
        }

        // Copie immuable du comportement par defaut historique.
        $this->assertStringContainsString(
            'synthèse concise',
            AdminAiPrompt::query()->where('scenario_id', 'chatloop_ai_summarize_fr')->value('prompt_text'),
        );
        $this->assertStringContainsString(
            'concise, structured summary',
            AdminAiPrompt::query()->where('scenario_id', 'chatloop_ai_summarize_en')->value('prompt_text'),
        );
    }

    public function test_an_admin_edited_prompt_is_never_overwritten_by_the_provisioning(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'like', 'chatloop_ai_summarize%')->delete();
        $admin = AdminAiPrompt::create([
            'scenario_id' => 'chatloop_ai_summarize_fr',
            'name' => 'Version administree',
            'prompt_text' => 'TEXTE ADMINISTRE PAR UN HUMAIN',
            'version' => 1,
            'is_active' => true,
        ]);
        $before = $admin->fresh()->getRawOriginal();

        // Rejouer la migration (idempotence) : la ligne administree est intacte.
        $this->provisionMigration()->up();
        $this->provisionMigration()->up();

        $this->assertSame($before, $admin->fresh()->getRawOriginal());
        $this->assertSame(
            1,
            AdminAiPrompt::query()->where('scenario_id', 'chatloop_ai_summarize_fr')->count(),
        );
    }

    public function test_the_summary_uses_the_localized_db_prompt_through_the_constitution(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'like', 'chatloop_ai_summarize%')->delete();
        AdminAiPrompt::create([
            'scenario_id' => 'chatloop_ai_summarize_fr',
            'name' => 'FR admin',
            'prompt_text' => 'SENTINELLE-PROMPT-DB-TASK1221',
            'version' => 7,
            'is_active' => true,
        ]);

        LoopSummaryAgent::fake([
            new TextResponse('Synthese de la Boucle.', new Usage(12, 6), new Meta('openai', 'gpt-4o-mini')),
        ]);

        app()->setLocale('fr');
        app(ChatLoopAiService::class)->summarize($this->loop, $this->owner);

        LoopSummaryAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();

            // Le prompt DB est bien celui envoye…
            $this->assertStringContainsString('SENTINELLE-PROMPT-DB-TASK1221', $instructions);
            // …compose SOUS la Constitution commune, jamais a sa place.
            $this->assertStringStartsWith('Constitution BouclePro IA', $instructions);
            // Et l'instruction de langue reste presente.
            $this->assertStringContainsString('répondre en français', $instructions);

            return true;
        });
    }

    // =====================================================================
    // B. Acceptance : parcours documentaire complet + ledger 1220
    // =====================================================================

    public function test_the_documentary_journey_links_query_and_generation_to_one_operation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The real retrieval journey requires PostgreSQL pgvector.');
        }

        $this->enableSemanticGate($this->organization);
        $this->fakeEmbeddings();
        // TASK-1294 : la question part de la Boucle — le corpus interroge
        // doit appartenir a son perimetre, ici en lui etant partage.
        [$dossier, $post] = $this->dossierFixture($this->organization, $this->owner, $this->loop);

        // 1. Ingestion reelle du corpus.
        app(DossierArticleIndexer::class)->synchronize($this->organization->id, $dossier->id, $post->id);

        // 2. Question reelle d'un membre : une NOUVELLE operation metier —
        // dans le produit, l'ingestion vit dans un job, la question dans une
        // requete ; ici le meme process de test partagerait la correlation
        // sans ce start() explicite.
        AiCorrelation::start();

        LoopKnowledgeAgent::fake([
            new TextResponse(
                'La valise contient le materiel itinerant [S1].',
                new Usage(50, 20),
                new Meta('openai', 'gpt-4o-mini'),
            ),
        ]);

        $answer = app(LoopKnowledgeAnswerService::class)
            ->answer($this->loop, $this->owner, 'Que contient la valise itinerante ?');

        // Reponse sourcee, ancree dans le corpus.
        $this->assertTrue($answer->grounded);
        $this->assertNotEmpty($answer->sources);
        $this->assertStringContainsString('[S1]', $answer->answer);

        // 3. La preuve de convergence TASK-1220 : l'operation a produit
        // exactement UNE query embedding et UNE generation, liees par la
        // MEME correlation metier, toutes deux au credential Organization.
        $rows = AiProviderInvocation::query()
            ->where('created_at', '>=', now()->subMinute())
            ->orderBy('created_at')
            ->get();

        $ingestion = $rows->firstWhere('embedding_operation', AiProviderInvocation::EMBEDDING_OPERATION_INGESTION);
        $query = $rows->firstWhere('embedding_operation', AiProviderInvocation::EMBEDDING_OPERATION_QUERY);
        $generation = $rows->firstWhere('operation', AiProviderInvocation::OPERATION_GENERATION);

        $this->assertNotNull($ingestion);
        $this->assertNotNull($query);
        $this->assertNotNull($generation);

        $this->assertSame('loop_knowledge_answer', $generation->capability);
        $this->assertSame('loop_knowledge_answer', $query->capability);
        $this->assertSame((string) $query->correlation_id, (string) $generation->correlation_id);
        // L'ingestion est une AUTRE operation metier : correlation distincte.
        $this->assertNotSame((string) $ingestion->correlation_id, (string) $generation->correlation_id);

        foreach ([$query, $generation] as $row) {
            $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
            $this->assertSame((string) $this->organization->id, (string) $row->organization_id);
        }

        // La trace P1 de la generation partage la meme operation.
        $interaction = AiInteraction::query()->where('feature', 'loop_knowledge_answer')->firstOrFail();
        $this->assertSame((string) $interaction->correlation_id, (string) $generation->correlation_id);
    }

    public function test_cross_tenant_member_never_reaches_another_organizations_corpus(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The real retrieval journey requires PostgreSQL pgvector.');
        }

        // Corpus indexe chez A.
        $this->enableSemanticGate($this->organization);
        $this->fakeEmbeddings();
        [$dossier, $post] = $this->dossierFixture($this->organization, $this->owner);
        app(DossierArticleIndexer::class)->synchronize($this->organization->id, $dossier->id, $post->id);

        // Membre de B, Boucle de B, gate ouverte pour B aussi.
        $orgB = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $orgB->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1221-b',
        ]);
        $memberB = User::factory()->create(['organization_id' => $orgB->id]);
        app()->instance('current_organization', $orgB);
        $loopB = (new LoopService)->createLoop($memberB, 'Boucle B');
        $this->enableSemanticGate($orgB);

        LoopKnowledgeAgent::fake(function (): never {
            throw new RuntimeException('No generation is expected without an accessible corpus.');
        });

        $ledgerABefore = AiProviderInvocation::query()->where('organization_id', $this->organization->id)->count();
        $idsBefore = AiProviderInvocation::query()->pluck('id')->all();

        $answer = app(LoopKnowledgeAnswerService::class)
            ->answer($loopB, $memberB, 'Que contient la valise itinerante ?');

        // Pas d'acces au corpus de A : reponse non ancree, zero source de A.
        $this->assertFalse($answer->grounded);
        $this->assertSame([], $answer->sources);

        // Rien n'a ete consomme AU NOM DE A : son compte ledger est inchange.
        $this->assertSame(
            $ledgerABefore,
            AiProviderInvocation::query()->where('organization_id', $this->organization->id)->count(),
        );

        // La question de B a legitimement produit sa PROPRE query embedding
        // (sur le Dossier systeme de sa Boucle, avec SON credential) — mais
        // JAMAIS une generation, et jamais une ligne hors de son tenant.
        $newRows = AiProviderInvocation::query()->whereNotIn('id', $idsBefore)->get();
        $this->assertNotEmpty($newRows);
        foreach ($newRows as $row) {
            $this->assertSame((string) $orgB->id, (string) $row->organization_id);
            $this->assertSame(AiProviderInvocation::OPERATION_EMBEDDING, $row->operation);
            $this->assertSame(AiProviderInvocation::EMBEDDING_OPERATION_QUERY, $row->embedding_operation);
            $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $row->credential_source);
        }
        $this->assertSame(0, AiInteraction::query()->where('organization_id', $orgB->id)->count());

        app()->instance('current_organization', $this->organization);
    }

    // =====================================================================
    // C. Contrat du systeme nerveux (garde-fou de la matrice)
    // =====================================================================

    public function test_the_capability_contracts_of_the_nervous_system_are_stable(): void
    {
        $registry = app(CapabilityRegistry::class);

        $clarify = $registry->get(CapabilityRegistry::CLARIFY_HELP_REQUEST);
        $this->assertTrue($clarify->requiresHumanConfirmation, 'clarify: the human validates BEFORE any publication');
        $this->assertFalse($clarify->canWrite);
        $this->assertSame(
            [CapabilityRegistry::SOURCE_ORGANIZATION_CATEGORIES, CapabilityRegistry::SOURCE_USER_LOOPS],
            $clarify->allowedSources,
        );

        $summary = $registry->get(CapabilityRegistry::LOOP_SUMMARY);
        $this->assertFalse($summary->canWrite, 'summary: read-only, never publishes');
        $this->assertSame([CapabilityRegistry::SOURCE_LOOP_MESSAGES], $summary->allowedSources);

        $knowledge = $registry->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER);
        $this->assertFalse($knowledge->canWrite, 'knowledge: read-only, never publishes');
        $this->assertSame(
            [CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL],
            $knowledge->allowedSources,
            'knowledge reads ONLY the documentary corpus',
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function provisionMigration(): Migration
    {
        return require base_path(self::PROVISION_MIGRATION);
    }

    /**
     * @return array{0: Dossier, 1: BlogPost}
     */
    private function dossierFixture(Organization $organization, User $owner, ?Loop $sharedWithLoop = null): array
    {
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1221 dossier '.Str::uuid(),
            'visibility' => $sharedWithLoop !== null ? Dossier::VISIBILITY_LOOP : Dossier::VISIBILITY_PRIVATE,
            'shared_with_loop_id' => $sharedWithLoop?->id,
        ]);

        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'TASK1221 valise itinerante '.Str::uuid(),
            'slug' => 'task1221-valise-'.Str::uuid(),
            'content' => '<p>La valise itinerante contient le materiel de l installation.</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return [$dossier, $post];
    }

    private function enableSemanticGate(Organization $organization): void
    {
        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.dossiers.semantic_search.organization_ids', array_unique(array_merge(
            config('ai.dossiers.semantic_search.organization_ids', []),
            [$organization->id],
        )));
    }

    private function fakeEmbeddings(): void
    {
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        $dimensions = config('database.default') === 'pgsql' ? 1536 : 8;
        config()->set('ai.providers.openai.models.embeddings.dimensions', $dimensions);

        Embeddings::fake(function (EmbeddingsPrompt $prompt) use ($dimensions): EmbeddingsResponse {
            $vectors = array_map(
                fn (): array => array_fill(0, $dimensions, 0.1),
                $prompt->inputs,
            );

            return new EmbeddingsResponse(
                $vectors,
                count($prompt->inputs) * 3,
                new Meta($prompt->provider->name(), $prompt->model),
            );
        })->preventStrayEmbeddings();
    }
}
