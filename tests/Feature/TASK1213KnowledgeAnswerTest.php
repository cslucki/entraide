<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1213 — RAG V1 : `dossier.retrieval` + `loop_knowledge_answer`.
 *
 * Le moteur pgvector est remplace ici par un double du service de recherche
 * (le SQL reel est couvert par PgvectorDossierRetrievalSourceTest, PostgreSQL
 * uniquement). Ces tests verifient le PERIMETRE (tenant, permissions, top-k),
 * la provenance, les citations, la degradation et — depuis TASK-1297 — la
 * persistance de l'echange dans le fil (l'ancien contrat read-only de
 * TASK-1213 est consciemment remplace).
 */
class TASK1213KnowledgeAnswerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $otherMember;

    private User $stranger;

    private Loop $loop;

    private Dossier $visibleDossier;

    private Dossier $privateDossier;

    private Dossier $foreignDossier;

    private FakeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $this->loop = (new LoopService)->createLoop($this->member, 'Boucle RAG');

        app()->instance('current_organization', $this->organization);

        // Un Dossier d'un autre membre PARTAGE avec la Boucle (TASK-1294 : la
        // question est posee depuis la Boucle, le perimetre est celui de la
        // Boucle — un Dossier hors Boucle, meme visible de l'Organization,
        // n'y entre plus), un Dossier prive d'un autre membre, un Dossier
        // d'un autre tenant.
        $this->visibleDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Dossier partagé',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);
        $this->privateDossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->otherMember->id,
            'name' => 'Dossier privé',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->foreignDossier = Dossier::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'owner_id' => $this->stranger->id,
            'name' => 'Dossier étranger',
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
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

        $this->search = new FakeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
    }

    // =====================================================================
    // Source dossier.retrieval : perimetre et provenance
    // =====================================================================

    public function test_the_source_only_searches_the_dossiers_the_user_can_view(): void
    {
        $this->search->rows = [$this->row('S-visible', $this->visibleDossier)];

        $borne = app(ContextBuilder::class)->build($this->contexte('Que contient une installation itinérante ?'), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $this->assertNotNull($this->search->lastCall);
        $this->assertSame($this->organization->id, $this->search->lastCall['organizationId']);
        $this->assertContains($this->visibleDossier->id, $this->search->lastCall['dossierIds']);
        $this->assertNotContains($this->privateDossier->id, $this->search->lastCall['dossierIds']);
        $this->assertNotContains($this->foreignDossier->id, $this->search->lastCall['dossierIds']);
        // Le credential est celui de l'Organization : instance SDK tenant.
        $this->assertSame('org:'.$this->organization->id.':openrouter', $this->search->lastCall['embeddingInstance']);
        // TASK-1307 : le manifest structurel (metadonnees, aucune recherche)
        // contribue lui aussi, sur ce meme Dossier visible.
        $this->assertSame([DossierManifestSource::NAME, DossierRetrievalSource::NAME], $borne->sourcesUsed);
    }

    public function test_top_k_is_capped_at_five_and_provenance_is_complete(): void
    {
        config(['ai.knowledge.top_k' => 9]);
        $this->search->rows = [$this->row('A', $this->visibleDossier), $this->row('B', $this->visibleDossier)];

        $borne = app(ContextBuilder::class)->build($this->contexte('question'), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $this->assertSame(5, $this->search->lastCall['limit']);
        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $this->assertCount(2, $provenance);
        $this->assertSame('S1', $provenance[0]['ref']);
        $this->assertSame('retrieval', $provenance[0]['type']);
        foreach (['chunk_id', 'dossier_id', 'dossier_name', 'blog_post_id', 'title', 'slug', 'distance', 'extrait', 'url'] as $key) {
            $this->assertArrayHasKey($key, $provenance[0]);
        }
        $this->assertSame($this->visibleDossier->id, $provenance[0]['dossier_id']);
        $this->assertStringContainsString('[S1] Article A', $borne->text);
        $this->assertStringContainsString('[S2] Article B', $borne->text);
    }

    public function test_the_source_is_denied_without_query_gate_or_tenant_provider(): void
    {
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER);

        $borne = app(ContextBuilder::class)->build($this->contexte(null), $definition);
        $this->assertSame(DossierRetrievalSource::REASON_NO_QUERY, $borne->sourcesDenied[DossierRetrievalSource::NAME]);

        config(['ai.dossiers.semantic_search.enabled' => false]);
        $borne = app(ContextBuilder::class)->build($this->contexte('q'), $definition);
        $this->assertSame(DossierRetrievalSource::REASON_SEMANTIC_SEARCH_DISABLED, $borne->sourcesDenied[DossierRetrievalSource::NAME]);
        config(['ai.dossiers.semantic_search.enabled' => true]);

        OrganizationAiSetting::query()->delete();
        $borne = app(ContextBuilder::class)->build($this->contexte('q'), $definition);
        $this->assertSame(DossierRetrievalSource::REASON_PROVIDER_NOT_CONFIGURED, $borne->sourcesDenied[DossierRetrievalSource::NAME]);
        $this->assertNull($this->search->lastCall);
    }

    public function test_an_embedding_provider_mismatch_is_refused_instead_of_mixing_vector_spaces(): void
    {
        OrganizationAiSetting::query()->update(['provider' => 'openai', 'model' => 'gpt-4o-mini']);
        config(['ai.providers.openai.driver' => 'openai', 'ai.providers.openai.key' => 'x']);

        $borne = app(ContextBuilder::class)->build($this->contexte('q'), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $this->assertSame(DossierRetrievalSource::REASON_EMBEDDING_PROVIDER_MISMATCH, $borne->sourcesDenied[DossierRetrievalSource::NAME]);
        $this->assertNull($this->search->lastCall);
    }

    // =====================================================================
    // Service : reponse, citations, degradation, persistance
    // =====================================================================

    public function test_a_question_gets_a_grounded_answer_with_cited_sources_and_a_trace(): void
    {
        $this->search->rows = [$this->row('A', $this->visibleDossier), $this->row('B', $this->visibleDossier)];
        $this->fakeAgent('Une installation itinérante doit tenir dans une valise [S1] et documenter ses contraintes [S2].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que doit contenir une installation itinérante ?');

        $this->assertTrue($answer->grounded);
        $this->assertCount(2, $answer->sources);
        $this->assertSame(['S1', 'S2'], array_column($answer->sources, 'ref'));
        $this->assertStringContainsString('[S1]', $answer->answer);

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertSame('org:'.$this->organization->id.':openrouter', $prompt->provider->name());
            $this->assertSame('openai/gpt-4o-mini', $prompt->model);
            $this->assertStringContainsString('SOURCES DOCUMENTAIRES', $prompt->prompt);
            $this->assertStringContainsString('Question du membre', $prompt->prompt);
            $this->assertStringContainsString('installation itinérante', $prompt->prompt);

            return true;
        });

        $interaction = AiInteraction::firstOrFail();
        $this->assertSame('loop_knowledge_answer', $interaction->feature);
        $this->assertSame('loop_knowledge.answer', $interaction->process);
        $this->assertSame('openrouter/openai/gpt-4o-mini', $interaction->model);
        $this->assertSame($this->organization->id, $interaction->organization_id);
        // TASK-1307 (revue) : `consulted` porte AUSSI le document racine de
        // la Boucle via le manifest (auto-cree par LoopRootDocumentService,
        // ref M1) en plus des 2 extraits de retrieval — `cited`, lui, ne
        // reflete que ce que le modele a REELLEMENT cite (S1, S2 : le
        // fakeAgent ne mentionne jamais [M1]).
        $this->assertCount(3, $interaction->metadata['retrieval']['consulted']);
        $this->assertCount(2, $interaction->metadata['retrieval']['cited']);
        $this->assertStringNotContainsString('sk-or-tenant', json_encode($interaction->toArray()));
        $this->assertSame($interaction->id, $answer->interactionId);
    }

    public function test_an_invented_citation_is_never_turned_into_a_source(): void
    {
        $this->search->rows = [$this->row('A', $this->visibleDossier)];
        $this->fakeAgent('Réponse qui cite une source inexistante [S9].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        $this->assertFalse($answer->grounded);
        // Rien de cite ne correspond : on montre ce qui a ete consulte, jamais
        // S9 — le manifest du document racine de la Boucle (M1, TASK-1307)
        // fait partie de ce qui a ete consulte, au meme titre que S1.
        $this->assertSame(['M1', 'S1'], array_column($answer->sources, 'ref'));
        $this->assertSame(['M1', 'S1'], array_column($answer->consulted, 'ref'));
    }

    public function test_without_relevant_sources_the_answer_says_so_without_calling_the_model(): void
    {
        // TASK-1307 (revue) : une Boucle porte TOUJOURS un document racine
        // publie (LoopRootDocumentService) que le manifest listerait sinon —
        // ce test isole volontairement le cas "aucune provenance du tout",
        // manifest COMPRIS, en depubliant ce document racine.
        $this->draftRootDocument($this->loop);
        $this->search->rows = [];
        $this->fakeAgent('ne doit pas etre appele');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, "Quel est le tarif d'adhésion ?");

        $this->assertSame(__('loops.knowledge_no_sources'), $answer->answer);
        $this->assertFalse($answer->grounded);
        $this->assertSame([], $answer->sources);
        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_an_organization_without_ai_configuration_gets_an_explicit_refusal(): void
    {
        OrganizationAiSetting::query()->delete();
        $this->fakeAgent('x');

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');
            $this->fail('Expected refusal.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('loops.ai_not_configured_for_organization'), $exception->getMessage());
        }

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertNull($this->search->lastCall);
    }

    public function test_the_organization_budget_refuses_before_retrieval_and_generation(): void
    {
        OrganizationAiSetting::query()->update(['monthly_budget_usd' => 0]);
        $this->fakeAgent('x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('loops.ai_summary_monthly_budget_reached'));

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');
        } finally {
            LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
            $this->assertNull($this->search->lastCall);
        }
    }

    public function test_a_missing_active_admin_prompt_is_an_explicit_unavailability(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'loop_knowledge_answer')->update(['is_active' => false]);
        $this->fakeAgent('x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('loops.knowledge_prompt_missing'));

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');
        } finally {
            LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
            $this->assertNull($this->search->lastCall);
        }
    }

    public function test_the_prompt_is_provisioned_by_migration_and_composed_after_the_constitution(): void
    {
        $prompt = AdminAiPrompt::query()->where('scenario_id', 'loop_knowledge_answer')->where('is_active', true)->first();
        $this->assertNotNull($prompt);
        // TASK-1307 (revue) : v2 est desormais la version active (migration
        // 2026_08_26_090000) — v1 reste en base, jamais modifiee, jamais
        // supprimee (donnee administrable, TASK-1211).
        $this->assertSame(2, $prompt->version);

        $this->search->rows = [$this->row('A', $this->visibleDossier)];
        $this->fakeAgent('ok [S1]');
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'question ?');

        LoopKnowledgeAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString('Constitution', $instructions);
            // TASK-1307 (revue) : marqueur propre au prompt v2 (absent de
            // v1) — prouve que c'est bien la version ACTIVE qui a ete
            // composee, pas une version quelconque.
            $this->assertStringContainsString('ELEMENTS DU DOSSIER', $instructions);
            $this->assertLessThan(strpos($instructions, 'ELEMENTS DU DOSSIER'), strpos($instructions, 'Constitution'));

            return true;
        });
    }

    // =====================================================================
    // TASK-1307 (revue) : manifest [Mn] comme provenance de premiere classe
    // =====================================================================

    /**
     * BLOCKER 1 de la revue : un Dossier avec un element reel (manifest non
     * vide) mais AUCUN chunk semantique pertinent ne doit plus etre refuse —
     * le manifest seul peut repondre a une question d'inventaire. Avant
     * cette revue, `$consulted` ne regardait que `DossierRetrievalSource` :
     * ce test aurait echoue avec `knowledge_no_sources` malgre un manifest
     * non vide (verifie par swap du code pre-revue, voir TASK file).
     */
    public function test_a_manifest_only_inventory_is_answered_without_any_semantic_hit(): void
    {
        $this->draftRootDocument($this->loop);
        $this->attachFile($this->visibleDossier, 'Regles-du-jeu.md', 'text/markdown');
        $this->search->rows = [];
        $this->fakeAgent('Le fichier Regles-du-jeu.md fait partie de cette Boucle [M1].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Quels fichiers sont disponibles dans cette Boucle ?');

        LoopKnowledgeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertNotSame(__('loops.knowledge_no_sources'), $answer->answer);
        $this->assertTrue($answer->grounded);
        $this->assertSame(['M1'], array_column($answer->sources, 'ref'));
        $this->assertSame('manifest', $answer->sources[0]['type']);
        $this->assertSame('Regles-du-jeu.md', $answer->sources[0]['title']);
    }

    /**
     * TEST_MIXED de la revue : une reponse peut combiner [Mn] (inventaire)
     * et [Sn] (contenu) dans la MEME reponse — les deux familles coexistent
     * sans collision de numerotation ([Mn] et [Sn] sont deux espaces
     * distincts, jamais fusionnes).
     */
    public function test_a_manifest_reference_and_a_retrieval_reference_can_be_cited_together(): void
    {
        $this->draftRootDocument($this->loop);
        $this->attachFile($this->visibleDossier, 'Regles-du-jeu.md', 'text/markdown');
        $this->search->rows = [$this->row('A', $this->visibleDossier)];
        $this->fakeAgent('Le fichier Regles-du-jeu.md fait partie de la Boucle [M1] et un autre document precise le contenu attendu [S1].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Quels fichiers y a-t-il et que documentent-ils ?');

        $this->assertTrue($answer->grounded);
        $this->assertSame(['M1', 'S1'], array_column($answer->sources, 'ref'));
    }

    /**
     * TEST_CONTENT de la revue : une question de CONTENU pur (aucune
     * question d'inventaire) reste portee par [Sn] uniquement quand le
     * modele ne cite aucun [Mn] — la seule existence d'un manifest non vide
     * ne force jamais une citation [Mn] non pertinente.
     */
    public function test_a_pure_content_question_is_answered_from_retrieval_alone(): void
    {
        $this->attachFile($this->visibleDossier, 'Regles-du-jeu.md', 'text/markdown');
        $this->search->rows = [$this->row('A', $this->visibleDossier), $this->row('B', $this->visibleDossier)];
        $this->fakeAgent('Une installation itinérante doit tenir dans une valise [S1] et documenter ses contraintes [S2].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Que doit contenir une installation itinérante ?');

        $this->assertTrue($answer->grounded);
        $this->assertSame(['S1', 'S2'], array_column($answer->sources, 'ref'));
    }

    /**
     * Propriete de securite explicite de la revue : une entree de provenance
     * `dossier.manifest` ne porte JAMAIS de contenu de document — ni
     * `extrait`, ni `chunk_id`. Sa forme publique (`KnowledgeAnswer::publicSource`)
     * n'expose donc jamais d'extrait pour une reference [Mn].
     */
    public function test_a_manifest_source_never_carries_document_content(): void
    {
        $this->draftRootDocument($this->loop);
        $this->attachFile($this->visibleDossier, 'Contrat-confidentiel.md', 'text/markdown');
        $this->search->rows = [];
        $this->fakeAgent('Un fichier existe dans cette Boucle [M1].');

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->member, 'Quels fichiers y a-t-il ?');

        $source = $answer->sources[0];
        $this->assertArrayNotHasKey('extrait', $source);
        $this->assertArrayNotHasKey('chunk_id', $source);
        $this->assertNull(\App\Services\Ai\DTO\KnowledgeAnswer::publicSource($source)['excerpt']);
    }

    // =====================================================================
    // Surface HTTP : persistance, tenant, membership
    // =====================================================================

    /**
     * TASK-1297 : le chemin knowledge n'est PLUS read-only. L'ancien contrat
     * (TASK-1213 : « aucun LoopMessage ») est consciemment remplacé : la
     * question du membre est publiée (type `user`) puis la réponse documentaire
     * liée (type `ai`, `reply_to_id`), sur le modèle exact de
     * `ChatLoopAiService::ask()` (TASK-1233).
     */
    public function test_the_endpoint_answers_members_in_json_and_persists_the_exchange(): void
    {
        $this->search->rows = [$this->row('A', $this->visibleDossier)];
        $this->fakeAgent('Réponse sourcée [S1].');
        $messagesBefore = LoopMessage::count();

        $response = $this->actingAs($this->member)->postJson(
            route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]),
            ['question' => 'Que doit contenir une installation itinérante ?'],
        );

        $response->assertOk()
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('sources.0.ref', 'S1')
            ->assertJsonPath('sources.0.title', 'Article A')
            ->assertJsonPath('sources.0.dossier_name', 'Dossier partagé')
            ->assertJsonMissingPath('sources.0.chunk_id');
        $this->assertStringContainsString('[S1]', $response->json('answer'));

        $this->assertSame($messagesBefore + 2, LoopMessage::count());

        $questionMessage = LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->where('type', 'user')
            ->where('body', 'Que doit contenir une installation itinérante ?')
            ->firstOrFail();
        $this->assertSame($this->member->id, $questionMessage->sender_id);
        $this->assertSame($this->organization->id, $questionMessage->organization_id);
        $this->assertTrue((bool) ($questionMessage->metadata['asked_knowledge_question'] ?? false));

        $aiMessage = LoopMessage::query()
            ->where('loop_id', $this->loop->id)
            ->where('type', 'ai')
            ->firstOrFail();
        $this->assertNull($aiMessage->sender_id);
        $this->assertSame($this->organization->id, $aiMessage->organization_id);
        $this->assertSame($questionMessage->id, $aiMessage->reply_to_id);
        $this->assertStringContainsString('[S1]', $aiMessage->body);

        $metadata = $aiMessage->metadata;
        $this->assertSame($this->member->id, $metadata['requested_by']);
        $this->assertSame('knowledge', $metadata['action']);
        $this->assertSame('Que doit contenir une installation itinérante ?', $metadata['question']);
        $this->assertTrue($metadata['grounded']);
        // Les sources vont en metadata (forme publique, jamais de chunk_id),
        // le corps reste le texte lisible.
        $this->assertSame(['S1'], array_column($metadata['sources'], 'ref'));
        $this->assertArrayNotHasKey('chunk_id', $metadata['sources'][0]);
        $this->assertSame('openrouter', $metadata['provider']);
        $this->assertSame('openai/gpt-4o-mini', $metadata['model']);
        $this->assertSame(AiInteraction::firstOrFail()->id, $metadata['ai_interaction_id']);

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_the_endpoint_refuses_non_members_short_questions_and_foreign_tenants(): void
    {
        $this->fakeAgent('x');
        $route = route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]);

        $this->actingAs($this->otherMember)->postJson($route, ['question' => 'question ?'])->assertNotFound();
        $this->actingAs($this->member)->postJson($route, ['question' => 'ab'])->assertUnprocessable();

        // Un membre d'une autre Organization : ni la Boucle, ni le slug ne lui
        // appartiennent — refus (404 : la Boucle n'existe pas pour lui).
        app()->forgetInstance('current_organization');
        $this->assertContains(
            $this->actingAs($this->stranger)->postJson($route, ['question' => 'question ?'])->getStatusCode(),
            [403, 404],
        );

        LoopKnowledgeAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => true);
        $this->assertNull($this->search->lastCall);
    }

    public function test_the_loop_page_exposes_the_knowledge_entry_point_to_members_only(): void
    {
        $this->actingAs($this->member)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $this->loop]))
            ->assertOk()
            ->assertSee('data-knowledge-open', false)
            ->assertSee('data-knowledge-modal', false)
            ->assertSee(__('loops.knowledge_button'));
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function contexte(?string $query): ContexteIa
    {
        return new ContexteIa(
            organizationId: $this->organization->id,
            userId: $this->member->id,
            loopId: $this->loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $query,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $label, Dossier $dossier, float $distance = 0.2): array
    {
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
            'chunk_index' => 0,
            'content' => "Contenu de l'article {$label} : une installation itinérante tient dans une valise et documente ses contraintes.",
            'distance' => $distance,
        ];
    }

    private function fakeAgent(string $text): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($text, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    /**
     * TASK-1307 (revue) : depublie le document racine auto-cree
     * (LoopRootDocumentService) pour isoler un scenario "manifest vide" ou
     * "un seul element connu" sans dependre de l'ordre d'insertion entre
     * plusieurs Dossiers accessibles.
     */
    private function draftRootDocument(Loop $loop): void
    {
        BlogPost::whereKey(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'))
            ->update(['status' => 'draft']);
    }

    private function attachFile(Dossier $dossier, string $name, string $mimeType): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->member->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.Str::uuid().'-'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }
}

/**
 * Double du moteur pgvector : renvoie des lignes canoniques et enregistre le
 * perimetre exact qui lui a ete demande.
 */
class FakeSearch extends DossierSemanticSearchService
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
