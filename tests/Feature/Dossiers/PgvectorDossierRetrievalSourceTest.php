<?php

namespace Tests\Feature\Dossiers;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Ai\ProviderResolver;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierArticleIndexer;
use App\Services\Dossiers\DossierFileIndexer;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

/**
 * TASK-1213 — la source `dossier.retrieval` sur le VRAI moteur pgvector :
 * perimetre intrinseque a la requete (Organization, Dossiers autorises — et,
 * depuis une Boucle, les Dossiers de CETTE Boucle, TASK-1294), un seul
 * embedding de requete sur l'instance SDK du tenant, top-k et ordre.
 *
 * PostgreSQL uniquement : sous SQLite le test est ignore (pas d'entree
 * supplementaire dans la reference des echecs connus).
 */
class PgvectorDossierRetrievalSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('dossier.retrieval integration requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }
    }

    public function test_only_accessible_dossiers_of_the_tenant_are_searched_with_the_tenant_embedding_instance(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $otherMember = User::factory()->create(['organization_id' => $organization->id]);
        $stranger = User::factory()->create(['organization_id' => $otherOrganization->id]);
        $loop = (new LoopService)->createLoop($member, 'Boucle pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'api_key' => 'sk-tenant']);
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 1.0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        // La question est posee DEPUIS la Boucle (TASK-1294) : le Dossier
        // eligible est celui partage avec elle ; un prive hors Boucle et un
        // Dossier d'un autre tenant restent dehors.
        $visible = $this->dossier($organization, $otherMember, Dossier::VISIBILITY_LOOP, 'Visible', $loop->id);
        $private = $this->dossier($organization, $otherMember, Dossier::VISIBILITY_PRIVATE, 'Privé');
        $foreign = $this->dossier($otherOrganization, $stranger, Dossier::VISIBILITY_ORGANIZATION, 'Étranger');

        $this->chunk($organization, $visible, $otherMember, $this->vector(0.3), 'Visible far');
        $near = $this->chunk($organization, $visible, $otherMember, $this->vector(0.0), 'Visible near');
        $this->chunk($organization, $private, $otherMember, $this->vector(0.0), 'Private near');
        $this->chunk($otherOrganization, $foreign, $stranger, $this->vector(0.0), 'Foreign near');

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $member->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'needle',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);

        $this->assertCount(2, $provenance);
        $this->assertSame($near->id, $provenance[0]['chunk_id']);
        $this->assertSame([$visible->id, $visible->id], array_column($provenance, 'dossier_id'));
        $this->assertStringNotContainsString('Private near', $borne->text);
        $this->assertStringNotContainsString('Foreign near', $borne->text);
        $this->assertStringContainsString('[S1] ', $borne->text);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['needle']
            && $prompt->provider->name() === 'org:'.$organization->id.':openrouter'
            && $prompt->model === 'openai/text-embedding-3-small');
    }

    public function test_an_article_ingested_via_the_tenant_instance_is_retrievable_end_to_end(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle ingestion pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-tenant-e2e',
        ]);
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openai',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openai.models.embeddings.default' => 'text-embedding-3-small',
            'ai.providers.openai.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
        ]);

        $seen = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (): array => $this->vector(0.0), $prompt->inputs);
        })->preventStrayEmbeddings();

        // Dossier du demandeur, partage avec la Boucle d'ou part la question
        // (TASK-1294), Article publie+attache.
        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_LOOP, 'Ingestion e2e', $loop->id);
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Station Quartz',
            'slug' => 'station-quartz-'.Str::uuid(),
            'content' => '<p>La Station Quartz utilise exactement 37 balises orange.</p>',
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

        // Ingestion reelle via l'instance tenant.
        $count = app(DossierArticleIndexer::class)
            ->synchronize($organization->id, $dossier->id, $post->id);
        $this->assertGreaterThan(0, $count);
        $this->assertContains('org:'.$organization->id.':openai', $seen);

        // Retrieval : le contenu ingere est retrouvable, cite avec sa source.
        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'Combien de balises utilise la Station Quartz ?',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $this->assertNotEmpty($provenance);
        $this->assertSame('Station Quartz', $provenance[0]['title']);
        $this->assertStringContainsString('37 balises orange', $borne->text);
        // La requete aussi passe par l'instance tenant.
        $this->assertContains('org:'.$organization->id.':openai', $seen);
    }

    public function test_a_file_ingested_via_the_tenant_instance_is_retrievable_end_to_end(): void
    {
        Storage::fake('dossier_files');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle ingestion fichier pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-tenant-file-e2e',
        ]);
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openai',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openai.models.embeddings.default' => 'text-embedding-3-small',
            'ai.providers.openai.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
        ]);

        $seen = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (): array => $this->vector(0.0), $prompt->inputs);
        })->preventStrayEmbeddings();

        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_LOOP, 'Ingestion fichier e2e', $loop->id);
        $content = 'The Orion Station has exactly 23 violet panels and its inspection takes place on Tuesday morning.';
        $path = 'dossier-files/'.$dossier->id.'/orion.txt';
        Storage::disk('dossier_files')->put($path, $content);
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => 'orion.txt',
            'display_name' => 'orion.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);

        $count = app(DossierFileIndexer::class)->synchronize($organization->id, $dossier->id, $file->id);
        $this->assertGreaterThan(0, $count);
        $this->assertContains('org:'.$organization->id.':openai', $seen);

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'How many panels does the Orion Station have?',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $this->assertNotEmpty($provenance);
        $this->assertSame('file', $provenance[0]['source_type']);
        $this->assertSame('orion.txt', $provenance[0]['title']);
        $this->assertSame($file->id, $provenance[0]['dossier_file_id']);
        $this->assertNull($provenance[0]['blog_post_id']);
        $this->assertStringContainsString('/dossiers/'.$dossier->id.'/files/'.$file->id, (string) $provenance[0]['url']);
        $this->assertStringContainsString('23 violet panels', $borne->text);
        $this->assertContains('org:'.$organization->id.':openai', $seen);
    }

    public function test_article_and_file_sources_coexist_in_the_same_retrieval(): void
    {
        Storage::fake('dossier_files');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle mixte pgvector');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'api_key' => 'sk-tenant-mixed',
        ]);
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openai',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openai.models.embeddings.default' => 'text-embedding-3-small',
            'ai.providers.openai.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_LOOP, 'Mixte', $loop->id);

        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Article mixte',
            'slug' => 'article-mixte-'.Str::uuid(),
            'content' => '<p>Contenu Article mixte.</p>',
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
        app(DossierArticleIndexer::class)->synchronize($organization->id, $dossier->id, $post->id);

        $path = 'dossier-files/'.$dossier->id.'/mixte.txt';
        Storage::disk('dossier_files')->put($path, 'Contenu fichier mixte.');
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => 'mixte.txt',
            'display_name' => 'mixte.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 21,
            'checksum_sha256' => hash('sha256', 'Contenu fichier mixte.'),
            'source' => 'upload',
        ]);
        app(DossierFileIndexer::class)->synchronize($organization->id, $dossier->id, $file->id);

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'mixte',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $sourceTypes = array_unique(array_column($provenance, 'source_type'));
        sort($sourceTypes);

        $this->assertSame(['article', 'file'], $sourceTypes);
    }

    /**
     * TASK-1307 : reproduit le bug reel constate sur `01-COMMUNICATION`
     * (`/ia explique moi le contenu de cette boucle`) — un document tres
     * pertinent avec BEAUCOUP de chunks proches ecrasait les 5 sources
     * citees, alors que d'autres documents pertinents existaient. Sans
     * diversification, les 5 meilleurs seraient les 5 premiers chunks de
     * « Dominant » (0.001..0.005) : zero diversite documentaire. Avec
     * `diversify()` (PER_DOCUMENT_CAP=2), au plus 2 chunks de CE document,
     * puis les documents suivants par distance croissante.
     */
    public function test_a_broad_question_is_not_dominated_by_a_single_document_with_many_close_chunks(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle diversification');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'api_key' => 'sk-diversify']);
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 1.0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_LOOP, 'Diversification', $loop->id);

        $dominant = $this->article($organization, $dossier, $owner, 'Dominant');
        foreach ([0.001, 0.002, 0.003, 0.004, 0.005, 0.006] as $index => $second) {
            $this->chunkOf($organization, $dossier, $dominant, $index, $this->vector($second), 'Dominant chunk '.$index);
        }

        $this->chunkOf($organization, $dossier, $this->article($organization, $dossier, $owner, 'Second'), 0, $this->vector(0.10), 'Second content');
        $this->chunkOf($organization, $dossier, $this->article($organization, $dossier, $owner, 'Third'), 0, $this->vector(0.11), 'Third content');
        $this->chunkOf($organization, $dossier, $this->article($organization, $dossier, $owner, 'Fourth'), 0, $this->vector(0.12), 'Fourth content');

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'explique moi le contenu de cette boucle',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);
        $titles = array_column($provenance, 'title');

        $this->assertCount(5, $provenance);
        $this->assertSame(2, count(array_filter($titles, fn (string $title): bool => $title === 'Dominant')), 'au plus 2 chunks du document dominant');
        $this->assertEqualsCanonicalizing(['Dominant', 'Dominant', 'Second', 'Third', 'Fourth'], $titles);
    }

    /**
     * TASK-1307 : la diversification ne doit JAMAIS appauvrir une question
     * precise dont un seul document est reellement pertinent — le repechage
     * de `diversify()` remplit les places restantes depuis CE document
     * quand aucun autre candidat n'existe pour diversifier.
     */
    public function test_a_precise_question_can_still_return_several_chunks_of_the_same_document(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $loop = (new LoopService)->createLoop($owner, 'Boucle precision');
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create(['organization_id' => $organization->id, 'api_key' => 'sk-precise']);
        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 1.0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $dossier = $this->dossier($organization, $owner, Dossier::VISIBILITY_LOOP, 'Precision', $loop->id);

        $solo = $this->article($organization, $dossier, $owner, 'Solo');
        foreach ([0.001, 0.002, 0.003, 0.004, 0.005] as $index => $second) {
            $this->chunkOf($organization, $dossier, $solo, $index, $this->vector($second), 'Solo chunk '.$index);
        }

        $borne = app(ContextBuilder::class)->build(new ContexteIa(
            organizationId: $organization->id,
            userId: $owner->id,
            loopId: $loop->id,
            locale: 'fr',
            capability: CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            correlationId: (string) Str::uuid(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: 'Que dit Solo precisement ?',
        ), app(CapabilityRegistry::class)->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER));

        $provenance = $borne->provenanceFor(DossierRetrievalSource::NAME);

        $this->assertCount(5, $provenance);
        $this->assertSame(['Solo', 'Solo', 'Solo', 'Solo', 'Solo'], array_column($provenance, 'title'));
    }

    /**
     * Revue MASTER (TASK-1216) : un fichier deplace A->B garde son id ;
     * seul `dossier_files.dossier_id` change. Avant que le job de nettoyage
     * asynchrone (dispatch sur l'observer `updated()`) n'ait tourne, le
     * chunk perime reste en base avec `dossier_chunks.dossier_id = A`.
     * `Queue::fake()` intercepte ce dispatch pour figer exactement cette
     * fenetre : le retrieval de A ne doit JAMAIS servir un contenu qui
     * appartient desormais a B, meme si le chunk stale est encore present.
     */
    public function test_a_file_moved_to_another_dossier_is_not_served_by_the_original_dossier_during_the_async_cleanup_window(): void
    {
        Storage::fake('dossier_files');
        Queue::fake();

        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'api_key' => 'sk-tenant-move-window',
        ]);
        config([
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openai',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openai.models.embeddings.default' => 'text-embedding-3-small',
            'ai.providers.openai.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $dossierA = $this->dossier($organization, $owner, Dossier::VISIBILITY_PRIVATE, 'Source A');
        $dossierB = $this->dossier($organization, $owner, Dossier::VISIBILITY_PRIVATE, 'Cible B');

        $content = 'Contenu confidentiel qui ne doit plus sortir du Dossier A une fois deplace.';
        $path = 'dossier-files/'.$dossierA->id.'/moved.txt';
        Storage::disk('dossier_files')->put($path, $content);
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossierA->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => 'moved.txt',
            'display_name' => 'moved.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);

        // Indexation reelle dans A (appel direct au service, pas via job).
        $count = app(DossierFileIndexer::class)->synchronize($organization->id, $dossierA->id, $file->id);
        $this->assertSame(1, $count);
        $this->assertSame(1, DossierChunk::query()->where('dossier_id', $dossierA->id)->where('dossier_file_id', $file->id)->count());

        // Deplacement A -> B : Queue::fake() empeche le job de nettoyage de
        // l'observer de s'executer -> le chunk stale reste dossier_id=A.
        $file->update(['dossier_id' => $dossierB->id]);
        $this->assertSame($dossierB->id, $file->fresh()->dossier_id);
        $this->assertSame(1, DossierChunk::query()->where('dossier_id', $dossierA->id)->where('dossier_file_id', $file->id)->count(), 'le chunk perime doit encore exister : c\'est la fenetre qu\'on teste');

        // Le retrieval de A ne doit rien servir malgre le chunk stale.
        // TASK-1283 : l'instance TENANT est desormais obligatoire — la meme
        // que celle de l'indexation ci-dessus (credential P4 de la fixture).
        $instance = app(ProviderResolver::class)->resolveEmbeddingInstance((string) $organization->id);
        $this->assertNotNull($instance);

        $resultsA = app(DossierSemanticSearchService::class)->search($organization->id, $dossierA->id, 'confidentiel', $instance);
        $this->assertCount(0, $resultsA);

        $resultsAcrossA = app(DossierSemanticSearchService::class)->searchAcrossDossiers($organization->id, [$dossierA->id], 'confidentiel', $instance);
        $this->assertCount(0, $resultsAcrossA);
    }

    private function dossier(Organization $organization, User $owner, string $visibility, string $name, ?string $sharedWithLoopId = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
            'shared_with_loop_id' => $sharedWithLoopId,
        ]);
    }

    private function chunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content): DossierChunk
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Article '.$content,
            'slug' => 'article-'.Str::uuid(),
            'content' => '<p>'.$content.'</p>',
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

        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content.Str::uuid()),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    /** Article attache a un Dossier, sans chunk — pour construire plusieurs chunks du MEME document via chunkOf(). */
    private function article(Organization $organization, Dossier $dossier, User $owner, string $title): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => $title,
            'slug' => 'article-'.Str::uuid(),
            'content' => '<p>'.$title.'</p>',
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

        return $post;
    }

    /** Un chunk supplementaire d'un Article DEJA attache (chunkIndex distinct, meme document). */
    private function chunkOf(Organization $organization, Dossier $dossier, BlogPost $post, int $chunkIndex, array $vector, string $content): DossierChunk
    {
        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'content_hash' => hash('sha256', $content.Str::uuid()),
            'token_count' => 3,
            'embedding' => $vector,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    /**
     * @return array<int, float>
     */
    private function vector(float $second): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $vector[1] = $second;

        return $vector;
    }
}
