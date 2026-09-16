<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1573 / CDC-01 V0-E — les sources sont quatre familles.
 *
 *  A. le pilote ecrit les QUATRE familles, coherentes avec `retrieval_trace`
 *     (compteurs identiques, un seul claim) et avec la borne (`used`) ;
 *  B. un refus de source porte sa RAISON (`denied[].reason`), une abstention
 *     dit ce qui a ete cherche et rien retenu ;
 *  C. chaque writer n'ecrit que ce qu'il A : Shell general et legacy
 *     (`used`/`denied` seuls), Dossier (`used`, `denied = []` mesure),
 *     `respondInThread` — pas de ContextBuilder — RIEN ;
 *  D. le formateur ne fabrique rien : familles absentes quand rien n'est donne,
 *     `[]` conserve comme mesure.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1573SourcesFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    private bool $rechercheRend = true;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1573']);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1573',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle des familles');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter', 'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.chatloop.min_summary_words' => 0,
            'ai.shell.enabled' => true, 'ai.clarify.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing(fn (): array => $this->rechercheRend ? [$this->ligne()] : [])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));
        ShellGeneralAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse generale.'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. le pilote

    public function test_a1_le_pilote_ecrit_les_quatre_familles_coherentes_avec_la_trace_de_retrieval(): void
    {
        $tour = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));
        $sources = $tour->metadata['turn']['sources'];
        $trace = $tour->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['dossier_retrieval'];

        $this->assertSame(['retrieved', 'reranked', 'used', 'denied'], array_keys($sources));

        // Les compteurs sont CEUX de la trace — un seul claim, deux cles.
        $this->assertSame($trace['dense_candidates_count'], $sources['retrieved']['candidates']);
        $this->assertSame($trace['after_distance_filter_count'], $sources['retrieved']['after_filter']);
        $this->assertSame($trace['final_context_count'], $sources['retrieved']['final']);
        $this->assertSame((bool) $trace['rerank_attempted'], $sources['reranked']['attempted']);

        // `used` est la borne : le retrieval documentaire a fourni.
        $this->assertContains(DossierRetrievalSource::NAME, $sources['used']);
        $this->assertSame($tour->metadata['sources_used'], $sources['used'], 'meme valeur que la cle legacy, jamais une autre');
        $this->assertSame([], $sources['denied']);
    }

    // ────────────────────────────── B. refus et abstention

    public function test_b1_une_source_refusee_porte_sa_raison(): void
    {
        // La recherche documentaire est coupee pour cette Organization : la
        // source la REFUSE avec une raison — et le manifest, lui, fournit.
        config(['ai.dossiers.semantic_search.enabled' => false]);

        $tour = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));
        $sources = $tour->metadata['turn']['sources'];

        $this->assertNotContains(DossierRetrievalSource::NAME, $sources['used']);
        $this->assertSame(
            [['source' => DossierRetrievalSource::NAME, 'reason' => DossierRetrievalSource::REASON_SEMANTIC_SEARCH_DISABLED]],
            $sources['denied'],
        );
        // Meme raison que la cle legacy `retrieval_trace.sources_denied` (map).
        $this->assertSame(
            [DossierRetrievalSource::NAME => DossierRetrievalSource::REASON_SEMANTIC_SEARCH_DISABLED],
            $tour->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['sources_denied'],
        );
    }

    public function test_b2_une_abstention_dit_ce_qui_a_ete_cherche_et_rien_retenu(): void
    {
        $this->rechercheRend = false;
        Dossier::query()->where('loop_id', $this->loop->id)->delete();

        $tour = $this->tour(fn () => $this->actingAs($this->membre)->postJson(
            route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]),
            ['question' => 'Que dit le document ?'],
        )->assertOk());

        $this->assertSame('abstained', $tour->metadata['status']);
        $sources = $tour->metadata['turn']['sources'];
        $this->assertSame(0, $sources['retrieved']['candidates']);
        $this->assertSame([], $sources['used']);
        $this->assertSame([], $sources['denied']);
    }

    // ────────────────────────────── C. chaque writer, ce qu'il a

    public function test_c1_les_writers_sans_retrieval_n_ecrivent_que_used_et_denied(): void
    {
        $legacy = $this->tour(fn () => app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Prochaine etape ?'));
        $this->assertSame(['used', 'denied'], array_keys($legacy->metadata['turn']['sources']));
        // Une Boucle sans message : la source `loop.messages` n'a rien fourni,
        // `used` est VIDE — et c'est la meme mesure que la cle legacy.
        $this->assertSame($legacy->metadata['sources_used'], $legacy->metadata['turn']['sources']['used']);
        $this->assertSame([], $legacy->metadata['turn']['sources']['denied']);

        $this->rechercheRend = false;
        $general = $this->tour(fn () => $this->shell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?'));
        $this->assertSame(AiExecutionPath::AI_SHELL_GENERAL, $general->metadata['turn']['identity']['execution_path']);
        $this->assertSame(['used', 'denied'], array_keys($general->metadata['turn']['sources']));
        $this->assertSame($general->metadata['sources_used'], $general->metadata['turn']['sources']['used']);
    }

    public function test_c2_le_moteur_dossier_ecrit_used_et_un_denied_vide_mesure(): void
    {
        $this->rechercheRend = true;
        $page = $this->tour(fn () => $this->actingAs($this->membre)->postJson(
            route('organization.dossiers.answer', ['organization' => $this->organization, 'dossier' => $this->dossier]),
            ['question' => 'Que dit le document ?'],
        )->assertOk());

        $sources = $page->metadata['turn']['sources'];
        $this->assertSame(['used', 'denied'], array_keys($sources), 'ni retrieved ni reranked : ce moteur ne passe pas par DossierRetrievalSource');
        $this->assertSame([DossierInsightsService::SOURCE_NAME], $sources['used']);
        $this->assertSame($page->metadata['sources_used'], $sources['used']);
        $this->assertSame([], $sources['denied']);
    }

    /**
     * Sabotage : passer `[]`/`[]` a `sourcesBlock()` dans
     * `ChatLoopAiService::recordInteraction` quand `extraMetadata` n'a rien →
     * ce test rougit : `respondInThread` n'a pas de ContextBuilder, il n'a
     * donc AUCUNE source a declarer — pas meme une liste vide.
     */
    public function test_c3_le_mode_ia_sans_context_builder_n_ecrit_aucune_famille(): void
    {
        $tour = $this->tour(fn () => $this->composeur('ia', 'Quelle est la capitale de la France ?'));

        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $tour->metadata['turn']['identity']['execution_path']);
        $this->assertArrayNotHasKey('sources', $tour->metadata['turn']);
    }

    // ────────────────────────────── D. le formateur

    public function test_d1_le_formateur_ne_fabrique_rien(): void
    {
        $this->assertSame([], AiTurnTrace::sourcesBlock(null, null, null));
        $this->assertSame(['used' => [], 'denied' => []], AiTurnTrace::sourcesBlock([], [], null), '[] est une mesure');
        $this->assertSame(
            ['used' => ['a'], 'denied' => [['source' => 'b', 'reason' => 'why']]],
            AiTurnTrace::sourcesBlock(['a'], ['b' => 'why'], ['pas_de_compteur' => 1]),
            'une trace sans compteurs ne produit ni retrieved ni reranked',
        );
        $bloc = AiTurnTrace::sourcesBlock(null, null, ['dense_candidates_count' => 3, 'after_distance_filter_count' => 2, 'final_context_count' => 1]);
        $this->assertSame(['retrieved'], array_keys($bloc), 'sans `rerank_attempted`, pas de `reranked`');
        $this->assertSame(['candidates' => 3, 'after_filter' => 2, 'final' => 1], $bloc['retrieved']);
    }

    // ────────────────────────────── harnais

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

    private function shell(string $kind, ?string $objectId, string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, $kind, $objectId, $kind === AiShellPageContext::KIND_DASHBOARD ? 'organization.dashboard' : 'organization.dossiers.show');
        $this->actingAs($this->membre);
        app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context);
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }

    /** @return array<string, mixed> */
    private function ligne(): array
    {
        return [
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ];
    }
}
