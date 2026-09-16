<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\Context\DossierRerankOutcome;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1574 / CDC-01 V0-F — le grounding se lit.
 *
 *  A. l'etape `grounding` dit ce qu'elle a verifie (SYNTAXIQUE) et combien
 *     elle a cite ; l'axe 2 en decoule honnetement (`supported` / `insufficient`) ;
 *  B. `retrieval` et `rerank` sont deposes PAR LA SOURCE, au bon moment, avec
 *     les memes valeurs que `retrieval_trace` ; un rerank non tente est
 *     `skipped` avec sa raison ;
 *  C. l'axe 3 : une source refusee → `DEGRADED_SOURCE_DENIED` ; un rerank
 *     tente et casse → `DEGRADED_PARTIAL_FAILURE` ; rien de connu → null ;
 *  D. les chemins sans grounding ecrivent `not_applicable`, jamais un
 *     `supported` de politesse ; C24 : aucune abstention n'est fabriquee sur
 *     preuve insuffisante — la reponse est rendue, l'axe 2 le dit ;
 *  E. l'Inspector lit `state` depuis le bloc (`source = turn`).
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1574GroundingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1574']);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1574',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle du grounding');
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
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([$this->ligne()])->byDefault();

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

    // ────────────────────────────── A. grounding

    public function test_a1_une_reponse_citee_est_supported_et_l_etape_dit_sa_methode(): void
    {
        $turn = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'))->metadata['turn'];

        $grounding = $this->etape($turn, 'grounding');
        $this->assertSame('executed', $grounding['status']);
        // Deux provenances consultees (manifest [M1] + retrieval [S1]), une citee.
        $this->assertSame(['consulted' => 2, 'cited' => 1, 'method' => 'syntactic_citations'], $grounding['metrics']);
        $this->assertArrayNotHasKey('reason_code', $grounding);

        $this->assertSame(AiTurnState::VERIFICATION_SUPPORTED, $turn['state']['verification_status']);
        $this->assertNull($turn['state']['degraded_reason']);
    }

    /**
     * C24. Sabotage : faire abstenir le moteur quand `cited === []` → la reponse
     * change (changement produit) et ce test rougit sur `status`.
     */
    public function test_a2_une_reponse_sans_citation_est_rendue_et_se_lit_insufficient_jamais_abstained(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Une reponse sans la moindre reference.'));

        $tour = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));
        $turn = $tour->metadata['turn'];

        // Le produit : repondu, non grounded — comme avant.
        $this->assertSame('success', $tour->metadata['status']);
        $this->assertSame(AiTurnState::TURN_ANSWERED, $turn['status']);
        // `grounded` vit sur la BULLE, pas sur l'interaction (FACT V0-A) : la
        // bulle IA porte `false`, comme avant.
        $this->assertFalse(LoopMessage::query()->where('type', 'ai')->latest('id')->firstOrFail()->metadata['grounded']);

        // La trace : l'etape a tourne et n'a rien cite ; l'axe 2 le dit.
        $this->assertSame(0, $this->etape($turn, 'grounding')['metrics']['cited']);
        $this->assertSame(AiTurnState::VERIFICATION_INSUFFICIENT, $turn['state']['verification_status']);
        // Aucun code fabrique : `NO_GROUNDED_EVIDENCE` reste reserve tant
        // qu'aucun chemin n'abstient sur preuve insuffisante (C24).
        $this->assertArrayNotHasKey('reason_code', $turn);
        $this->assertContains('NO_GROUNDED_EVIDENCE', AiTurnReason::reservedVocabulary());
    }

    // ────────────────────────────── B. retrieval / rerank par la source

    public function test_b1_retrieval_et_rerank_sont_deposes_par_la_source_avec_les_valeurs_de_la_trace(): void
    {
        $tour = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));
        $turn = $tour->metadata['turn'];
        $trace = $tour->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['dossier_retrieval'];

        $retrieval = $this->etape($turn, 'retrieval');
        $this->assertSame('executed', $retrieval['status']);
        $this->assertSame($trace['dense_candidates_count'], $retrieval['metrics']['candidates']);
        $this->assertSame($trace['after_distance_filter_count'], $retrieval['metrics']['after_filter']);
        $this->assertSame($trace['final_context_count'], $retrieval['metrics']['final']);

        // Rerank non configure sur ce banc : `skipped`, raison de la famille 4,
        // identique a `reason_not_attempted` de la trace.
        $rerank = $this->etape($turn, 'rerank');
        $this->assertSame('skipped', $rerank['status']);
        $this->assertFalse($trace['rerank_attempted']);
        $this->assertSame($trace['reason_not_attempted'], $rerank['reason_code'] ?? null);
        if (isset($rerank['reason_code'])) {
            $this->assertTrue(AiTurnReason::isKnown($rerank['reason_code']));
        }

        // Et l'ORDRE est celui de l'execution : la source depose pendant le
        // builder, donc AVANT l'etape `context_builder` du moteur.
        $noms = array_column($turn['steps'], 'name');
        $this->assertLessThan(array_search('context_builder', $noms, true), array_search('retrieval', $noms, true));
        $this->assertLessThan(array_search('context_builder', $noms, true), array_search('rerank', $noms, true));
    }

    // ────────────────────────────── C. axe 3

    public function test_c1_une_source_refusee_degrade_le_tour(): void
    {
        config(['ai.dossiers.semantic_search.enabled' => false]);

        $turn = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'))->metadata['turn'];

        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, $turn['state']['degraded_reason']);
        $this->assertSame(DossierRetrievalSource::NAME, $turn['sources']['denied'][0]['source']);
    }

    public function test_c2_le_formateur_de_l_etat_traduit_sans_deriver(): void
    {
        // Axe 2 depuis `grounded`, et lui seul.
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, AiTurnTrace::stateBlock(null, [])['verification_status']);
        $this->assertSame(AiTurnState::VERIFICATION_SUPPORTED, AiTurnTrace::stateBlock(true, [])['verification_status']);
        $this->assertSame(AiTurnState::VERIFICATION_INSUFFICIENT, AiTurnTrace::stateBlock(false, [])['verification_status']);

        // Axe 3 : rien → null ; rerank tente et casse → partial ; refus prime.
        $this->assertNull(AiTurnTrace::stateBlock(true, [], ['rerank_attempted' => false])['degraded_reason']);
        $this->assertNull(AiTurnTrace::stateBlock(true, [], ['rerank_attempted' => true, 'rerank_succeeded' => true])['degraded_reason']);
        $this->assertSame(AiTurnState::DEGRADED_PARTIAL_FAILURE, AiTurnTrace::stateBlock(true, [], ['rerank_attempted' => true, 'rerank_succeeded' => false])['degraded_reason']);
        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, AiTurnTrace::stateBlock(true, ['x' => 'why'], ['rerank_attempted' => true, 'rerank_succeeded' => false])['degraded_reason']);

        // Les deux axes ne se derivent pas l'un de l'autre.
        $this->assertSame(AiTurnState::VERIFICATION_INSUFFICIENT, AiTurnTrace::stateBlock(false, ['x' => 'why'])['verification_status']);
        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, AiTurnTrace::stateBlock(false, ['x' => 'why'])['degraded_reason']);
        $this->assertTrue(AiTurnReason::isKnown(AiTurnState::DEGRADED_PARTIAL_FAILURE));
        $this->assertSame(DossierRerankOutcome::REASON_PROVIDER_UNAVAILABLE, AiTurnReason::RERANK_PROVIDER_UNAVAILABLE);
    }

    // ────────────────────────────── D. sans grounding

    public function test_d1_les_chemins_sans_grounding_ecrivent_not_applicable(): void
    {
        $ia = $this->tour(fn () => $this->composeur('ia', 'Capitale ?'))->metadata['turn'];
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $ia['state']['verification_status']);
        $this->assertNull($ia['state']['degraded_reason']);
        $this->assertArrayNotHasKey('grounding', array_column($ia['steps'], 'status', 'name'));

        $legacy = $this->tour(fn () => app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Prochaine etape ?'))->metadata['turn'];
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $legacy['state']['verification_status']);

        $this->mock(DossierSemanticSearchService::class)->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault()
            ->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $general = $this->tour(fn () => $this->shell('Quelle est la capitale de la France ?'))->metadata['turn'];
        $this->assertSame(AiTurnState::VERIFICATION_NOT_APPLICABLE, $general['state']['verification_status']);
    }

    // ────────────────────────────── E. inspector

    public function test_e1_l_inspector_lit_state_depuis_le_bloc(): void
    {
        $tour = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));

        Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $tour->id, '--json' => true]);
        $trace = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('turn', $trace['state']['source']);
        $this->assertSame(AiTurnState::VERIFICATION_SUPPORTED, $trace['state']['verification_status']);
        $this->assertSame(['retrieval', 'rerank', 'grounding'], array_values(array_intersect(array_column($trace['steps'], 'name'), ['retrieval', 'rerank', 'grounding'])));
    }

    // ────────────────────────────── harnais

    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    /** @return array<string, mixed> */
    private function etape(array $turn, string $nom): array
    {
        foreach ($turn['steps'] as $etape) {
            if ($etape['name'] === $nom) {
                return $etape;
            }
        }
        $this->fail("etape `{$nom}` absente");
    }

    private function composeur(string $mode, string $question): void
    {
        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])->call('setComposerMode', $mode)->set('body', $question)->call('sendMessage')->assertHasNoErrors();
    }

    private function shell(string $question): void
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, AiShellPageContext::KIND_DASHBOARD, null, 'organization.dashboard');
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
