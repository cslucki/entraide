<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\BlogPost;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnProjection;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1580 — Inspector V0 : projection de lecture persistee.
 *
 * `AiTurnProjection` est le SEUL sibling autorise a requeter (2 requetes,
 * tenant-bound) ; `AiTurnInspection` reste query-free. Tout ce qui manque
 * est UNAVAILABLE avec sa raison ; `ai_interactions.prompt` n'est jamais lu.
 */
/**
 * TASK-1595 — le mode exerce ici est `ia_dossiers`.
 *
 * Le sujet de cette suite est `AiTurnProjection` : ce qu'elle projette des
 * chunks que le writer a MESURES dans `retrieval.consulted`. Elle a donc besoin
 * d'un pipeline qui ecrit dans cette cle exactement ce que la recherche a
 * rendu. `loop_chat.dossiers` repond desormais par `DossierInsightsService`,
 * qui replie les quasi-doublons et ajoute une ancre d'ouverture : le jeu final
 * n'est plus celui que le test a injecte, et ce que la suite mesurerait alors
 * serait le repli, pas la projection.
 *
 * `ia_dossiers` conserve le pipeline sans repli. La projection, elle, est
 * identique pour les deux : elle lit une liste d'ids, d'ou qu'elle vienne.
 */
#[Group('ai')]
class TASK1580ProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    /** @var list<array<string, mixed>> les lignes que la recherche rend */
    private array $lignes = [];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1580', 'loops_enabled' => true, 'members_can_create_loops' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1580',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle projetee');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id,
            'name' => 'Dossier projete', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true,
        ]);

        // Par defaut : deux VRAIS chunks (fichier + article), la reponse cite le premier.
        $fichier = $this->chunkFichier();
        $article = $this->chunkArticle();
        $this->lignes = [$this->ligne($fichier, 'file', 0.2), $this->ligne($article, 'article', 0.3)];

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing(fn (): array => $this->lignes)->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. la projection

    public function test_a1_un_tour_avec_bulle_projette_la_question_et_les_sources_vues(): void
    {
        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        $bulle = LoopMessage::query()->where('type', 'ai')->where('metadata->ai_interaction_id', (string) $interaction->id)->firstOrFail();

        $p = $this->expliquer($interaction)['projection'];

        $this->assertSame((string) $bulle->id, $p['loop_message_id']);
        $this->assertSame('Que dit le document ?', $p['question']);
        $this->assertSame(AiTruthLabel::MEASURED, $p['truth']['question']);
        $this->assertNotEmpty($p['sources']);
        $this->assertSame('S1', $p['sources'][0]['ref']);
        $this->assertArrayHasKey('excerpt', $p['sources'][0]);
        $this->assertSame(AiTruthLabel::MEASURED, $p['truth']['sources']);
        $this->assertSame([], $p['unavailable_reasons']);
    }

    public function test_a2_un_tour_cli_sans_bulle_a_une_question_unavailable_jamais_le_prompt(): void
    {
        $interaction = $this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));
        $this->assertStringContainsString('Que dit le document ?', (string) $interaction->prompt, 'le prompt porte la question : il ne doit PAS servir');

        $p = $this->expliquer($interaction)['projection'];

        $this->assertNull($p['loop_message_id']);
        $this->assertNull($p['question']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $p['truth']['question']);
        $this->assertSame('no_loop_bubble', $p['unavailable_reasons']['question']);
        $this->assertNull($p['sources']);
        // Les chunks, eux, viennent de l'interaction : disponibles.
        $this->assertCount(2, $p['chunks']);
    }

    public function test_a3_source_type_est_derive_des_colonnes_du_chunk_document_article_connaissance_derivee(): void
    {
        $note = $this->chunkNoteDerivee();
        $this->lignes[] = $this->ligne($note, 'derived_knowledge', 0.4);

        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        $p = $this->expliquer($interaction)['projection'];

        $types = array_column($p['chunks'], 'source_type', 'chunk_id');
        $this->assertSame(AiTurnProjection::SOURCE_TYPE_FILE, $types[(string) $this->lignes[0]['chunk_id']]);
        $this->assertSame(AiTurnProjection::SOURCE_TYPE_ARTICLE, $types[(string) $this->lignes[1]['chunk_id']]);
        $this->assertSame(AiTurnProjection::SOURCE_TYPE_DERIVED_KNOWLEDGE, $types[(string) $note->id]);
        foreach ($p['chunks'] as $chunk) {
            $this->assertSame(AiTruthLabel::DERIVED, $p['truth']['chunks.'.$chunk['chunk_id'].'.source_type']);
            $this->assertTrue($chunk['present']);
        }
    }

    public function test_a4_un_chunk_supprime_est_unavailable_sans_casser_les_autres(): void
    {
        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        DossierChunk::query()->whereKey($this->lignes[1]['chunk_id'])->delete();

        $p = $this->expliquer($interaction)['projection'];

        $parId = array_column($p['chunks'], null, 'chunk_id');
        $supprime = $parId[(string) $this->lignes[1]['chunk_id']];
        $intact = $parId[(string) $this->lignes[0]['chunk_id']];

        $this->assertFalse($supprime['present']);
        $this->assertNull($supprime['source_type']);
        $this->assertSame('chunk_missing_or_reindexed', $supprime['unavailable_reason']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $p['truth']['chunks.'.$supprime['chunk_id'].'.source_type']);
        $this->assertSame(AiTurnProjection::SOURCE_TYPE_FILE, $intact['source_type']);
        // Les ids restent ceux MESURES par le writer, meme pour le disparu.
        $this->assertCount(2, $p['chunks']);
    }

    public function test_a5_consulted_not_cited_est_derive_des_ids_du_writer(): void
    {
        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        $p = $this->expliquer($interaction)['projection'];

        // La reponse cite [S1] = le premier chunk ; le second est consulte, non cite.
        $this->assertSame([(string) $this->lignes[1]['chunk_id']], $p['consulted_not_cited']);
        $this->assertSame(AiTruthLabel::DERIVED, $p['truth']['consulted_not_cited']);
        $this->assertTrue(collect($p['chunks'])->firstWhere('chunk_id', (string) $this->lignes[0]['chunk_id'])['cited']);
        $this->assertFalse(collect($p['chunks'])->firstWhere('chunk_id', (string) $this->lignes[1]['chunk_id'])['cited']);

        // Un tour sans retrieval (arret avant) : UNAVAILABLE, pas [].
        $sans = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer', 'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => '', 'response' => null,
            'input_tokens' => 0, 'output_tokens' => 0, 'metadata' => ['status' => 'refused'],
        ]);
        $p = AiTurnProjection::project($sans);
        $this->assertNull($p['consulted_not_cited']);
        $this->assertSame('retrieval_ids_unavailable', $p['unavailable_reasons']['consulted_not_cited']);
        $this->assertSame([], $p['chunks']);
    }

    public function test_a6_la_projection_ne_revele_rien_d_un_autre_tenant(): void
    {
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1580']);
        $etranger = User::factory()->create(['organization_id' => $ailleurs->id]);
        $dossierEtranger = Dossier::factory()->create(['organization_id' => $ailleurs->id, 'owner_id' => $etranger->id, 'name' => 'Etranger', 'visibility' => 'private']);
        $chunkEtranger = $this->chunkFichier($ailleurs, $dossierEtranger);

        // Une interaction de CE tenant qui reference (donnee forgee) un chunk d'ailleurs.
        $interaction = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer', 'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1,
            'metadata' => ['status' => 'completed', 'retrieval' => ['consulted' => [['chunk_id' => (string) $chunkEtranger->id]], 'cited' => []]],
        ]);
        // Et une bulle d'ailleurs qui pretend porter cette interaction.
        $loopEtrangere = Loop::factory()->create(['organization_id' => $ailleurs->id, 'created_by' => $etranger->id]);
        LoopMessage::create(['loop_id' => $loopEtrangere->id, 'organization_id' => $ailleurs->id, 'sender_id' => null, 'type' => 'ai', 'body' => 'x', 'metadata' => ['question' => 'QUESTION ETRANGERE', 'ai_interaction_id' => (string) $interaction->id]]);

        $p = AiTurnProjection::project($interaction);

        $this->assertNull($p['question'], 'la bulle d\'ailleurs n\'est pas lue');
        $this->assertFalse($p['chunks'][0]['present'], 'le chunk d\'ailleurs est « absent », pas « d\'ailleurs »');
        $this->assertNull($p['chunks'][0]['source_type']);
        $this->assertStringNotContainsString('QUESTION ETRANGERE', json_encode($p, JSON_THROW_ON_ERROR));
    }

    public function test_a7_deux_requetes_quel_que_soit_le_nombre_de_chunks(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->lignes[] = $this->ligne($this->chunkFichier(), 'file', 0.5);
        }
        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        // `retrieval.consulted` porte aussi les entrees du manifest (sans chunk_id) : seuls les chunks comptent.
        // Le moteur borne le contexte final (top-k) : on compte ce qu'il a ECRIT.
        $n = count(array_filter(array_column($interaction->metadata['retrieval']['consulted'], 'chunk_id')));
        $this->assertGreaterThanOrEqual(3, $n);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $p = AiTurnProjection::project($interaction);
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount($n, $p['chunks']);
        $this->assertCount(2, $requetes, 'une requete pour la bulle, UNE pour tous les chunks : '.json_encode(array_column($requetes, 'query')));
    }

    public function test_a8_un_ancien_tour_avec_des_ids_de_chunk_non_uuid_ne_requete_pas_et_le_dit(): void
    {
        $ancien = AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer', 'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 1, 'output_tokens' => 1,
            'metadata' => ['status' => 'completed', 'retrieval' => ['consulted' => ['c1', (string) $this->lignes[0]['chunk_id']], 'cited' => ['c1']]],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $p = AiTurnProjection::project($ancien);
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        $parId = array_column($p['chunks'], null, 'chunk_id');
        $this->assertSame('chunk_id_not_uuid', $parId['c1']['unavailable_reason']);
        $this->assertFalse($parId['c1']['present']);
        $this->assertSame(AiTurnProjection::SOURCE_TYPE_FILE, $parId[(string) $this->lignes[0]['chunk_id']]['source_type']);
        $this->assertSame([(string) $this->lignes[0]['chunk_id']], $p['consulted_not_cited']);
        $this->assertCount(2, $requetes);
        $this->assertStringNotContainsString("'c1'", json_encode(array_column($requetes, 'bindings')), 'un id non-uuid ne part jamais en requete');
    }

    // ────────────────────────────── B. la commande

    public function test_b1_la_section_projection_est_dans_le_contrat_explain_et_nulle_sans_interaction(): void
    {
        $interaction = $this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?'));
        $trace = $this->expliquer($interaction);

        $this->assertSame('projection', array_key_last($trace));
        $this->assertSame(
            ['ai_interaction_id', 'loop_message_id', 'question', 'sources', 'consulted_public', 'chunks', 'consulted_not_cited', 'unavailable_reasons', 'truth'],
            array_keys($trace['projection']),
        );
        // T1539 : la note derivee n'est jamais nommee par la projection.
        foreach ($trace['projection']['chunks'] as $chunk) {
            $this->assertArrayNotHasKey('derived_knowledge_note_id', $chunk);
        }
        $this->assertSame($trace, $this->expliquer($interaction), 'deterministe');
    }

    public function test_b2_les_cles_non_uuid_sont_refusees_proprement(): void
    {
        // --organization : ni slug connu ni uuid -> introuvable, pas une QueryException.
        $this->artisan('ai:inspect-turn', ['--organization' => 'pas-un-slug-ni-un-uuid', '--interaction' => (string) Str::uuid(), '--json' => true])
            ->expectsOutputToContain('Organization introuvable')->assertExitCode(1);
        // --user : ni email ni uuid.
        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--user' => 'pas-un-email', '--loop' => (string) $this->loop->id, '--question' => 'Q ?', '--json' => true])
            ->expectsOutputToContain('Utilisateur introuvable')->assertExitCode(1);
        // --loop : pas un uuid.
        $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => 'pas-un-uuid', '--question' => 'Q ?', '--json' => true])
            ->expectsOutputToContain('--loop doit etre un uuid')->assertExitCode(1);

        $this->assertSame(0, AiInteraction::query()->count(), 'aucun refus n\'execute rien');
    }

    // ────────────────────────────── fixtures

    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    private function composeur(string $mode, string $question): void
    {
        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])->call('setComposerMode', $mode)->set('body', $question)->call('sendMessage')->assertHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function expliquer(AiInteraction $interaction): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $interaction->id, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }

    private function chunkFichier(?Organization $organization = null, ?Dossier $dossier = null): DossierChunk
    {
        $organization ??= $this->organization;
        $dossier ??= $this->dossier;
        $file = DossierFile::factory()->create(['organization_id' => $organization->id, 'dossier_id' => $dossier->id]);

        return $this->chunk(['organization_id' => $organization->id, 'dossier_id' => $dossier->id, 'dossier_file_id' => $file->id, 'blog_post_id' => null]);
    }

    private function chunkArticle(): DossierChunk
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->membre->id,
            'title' => 'Article projete', 'slug' => 'article-projete-'.Str::random(6), 'content' => '<p>Contenu</p>',
            'status' => 'published', 'published_at' => now()->subMinute(),
        ]);

        return $this->chunk(['organization_id' => $this->organization->id, 'dossier_id' => $this->dossier->id, 'blog_post_id' => $post->id, 'dossier_file_id' => null]);
    }

    private function chunkNoteDerivee(): DossierChunk
    {
        $note = DerivedKnowledgeNote::create([
            'organization_id' => $this->organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $this->loop->id,
            'dossier_id' => $this->dossier->id,
            'subject_key' => 'sujet',
            'content' => 'Une connaissance derivee.',
            'source_fingerprint' => hash('sha256', 'sujet'),
            'provenance' => ['source_loop_message_ids' => [], 'derived_by' => 'test'],
            'observed_at' => now(),
            'derived_at' => now(),
            'version' => 1,
            'status' => 'active',
        ]);

        return $this->chunk(['organization_id' => $this->organization->id, 'dossier_id' => $this->dossier->id, 'blog_post_id' => null, 'dossier_file_id' => null, 'derived_knowledge_note_id' => $note->id]);
    }

    /** @param  array<string, mixed>  $attributs */
    private function chunk(array $attributs): DossierChunk
    {
        $dimensions = config('database.default') === 'pgsql' ? 1536 : 8;

        return DossierChunk::create($attributs + [
            'chunk_index' => 0,
            'content' => 'contenu de test',
            'content_hash' => hash('sha256', (string) Str::uuid()),
            'token_count' => 3,
            'embedding' => array_fill(0, $dimensions, 0.1),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function ligne(DossierChunk $chunk, string $type, float $distance): array
    {
        return [
            'chunk_id' => (string) $chunk->id, 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name,
            'source_type' => $type, 'blog_post_id' => $chunk->blog_post_id !== null ? (string) $chunk->blog_post_id : null, 'title' => $type === 'article' ? 'Article projete' : null, 'slug' => null,
            'dossier_file_id' => $chunk->dossier_file_id !== null ? (string) $chunk->dossier_file_id : null, 'filename' => $type === 'file' ? 'note.docx' : null,
            'mime_type' => $type === 'file' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : null,
            'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => $distance,
        ];
    }
}
