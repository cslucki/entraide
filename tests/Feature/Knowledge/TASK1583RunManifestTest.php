<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1583 / CDC-02 TRACE-1B — « un run a un nom ».
 *
 * B1 run CLI de 3 tours : manifeste + `turn.run` ×3, `ai:run-manifest` les
 * relit, la requete de secours retrouve les memes ids ; B2 manifeste sans
 * cle JSON (browser) lu depuis le fichier seul ; B3 aucun texte, aucun
 * secret ; s2 manifeste corrompu refuse. Schema 2 = v1 + `run` optionnel.
 */
#[Group('ai')]
class TASK1583RunManifestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    /** @var list<string> manifestes crees, effaces au tearDown */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1583']);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1583']);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle nommee');
        $dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'D', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key', 'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [], 'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id], 'ai.knowledge.retrieval_trace.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $dossier->id, 'dossier_name' => $dossier->name, 'source_type' => 'file',
            'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chunk_index' => 0, 'content' => 'SECRET CONTENU DU DOCUMENT', 'distance' => 0.2,
        ]])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->runs as $runId) {
            File::delete(AiRunManifest::path($runId));
        }
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. schema 2

    public function test_a1_un_tour_hors_de_tout_run_est_en_schema_2_sans_lien_run(): void
    {
        $turn = $this->tour()->metadata['turn'];

        $this->assertSame(2, $turn['schema']);
        $this->assertArrayNotHasKey('run', $turn, 'hors run : aucun lien, pas un lien vide');
        // La v1 reste lisible : memes cles qu'avant, `run_id` UNAVAILABLE.
        $trace = $this->expliquer($this->tour());
        $this->assertNull($trace['run']['run_id']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $trace['truth']['run.run_id']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $trace['truth']['run.run_kind']);
    }

    public function test_a2_le_contexte_de_run_est_recopie_par_le_writer_puis_retire(): void
    {
        $runId = (string) Str::uuid();
        AiTurnTrace::beginRun($runId, AiTurnTrace::RUN_KIND_LAB, 'enrica-2-tours');
        $dans = $this->tour();
        AiTurnTrace::endRun();
        $hors = $this->tour();

        $this->assertSame(['id' => $runId, 'kind' => 'lab', 'lab_scenario_key' => 'enrica-2-tours'], $dans->metadata['turn']['run']);
        $this->assertArrayNotHasKey('run', $hors->metadata['turn']);

        $trace = $this->expliquer($dans);
        $this->assertSame($runId, $trace['run']['run_id']);
        $this->assertSame('lab', $trace['run']['run_kind']);
        $this->assertSame(AiTruthLabel::MEASURED, $trace['truth']['run.run_id']);
        $this->assertSame(AiTruthLabel::DECLARED, $trace['truth']['run.run_kind']);
        $this->assertSame(AiTruthLabel::DECLARED, $trace['truth']['run.lab_scenario_key']);

        $this->expectException(\InvalidArgumentException::class);
        AiTurnTrace::beginRun((string) Str::uuid(), 'sabotage');
    }

    // ────────────────────────────── B. le manifeste

    public function test_b1_un_run_cli_de_trois_tours_porte_turn_run_et_se_relit(): void
    {
        $runId = $this->runId();

        for ($i = 1; $i <= 3; $i++) {
            $code = Artisan::call('ai:inspect-turn', [
                '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
                '--mode' => 'dossiers', '--question' => "Question {$i} ?", '--run-id' => $runId, '--json' => true,
            ]);
            $this->assertSame(0, $code, Artisan::output());
            AiTurnLock::forgetRequestState();
        }

        // Les 3 blocs portent le lien ; le contexte a ete retire apres chaque tour.
        $this->assertNull(AiTurnTrace::currentRun());
        $this->assertSame(3, AiInteraction::query()->where('metadata->turn->run->id', $runId)->count());

        $manifeste = AiRunManifest::load($runId);
        $this->assertSame('cli', $manifeste['run_kind']);
        $this->assertSame((string) $this->organization->id, $manifeste['organization_id']);
        $this->assertCount(3, $manifeste['turns']);
        $this->assertSame([1, 2, 3], array_column($manifeste['turns'], 'order'));
        $this->assertNotNull($manifeste['tool_versions']['app_version']);

        // ai:run-manifest relit tout, et le secours est coherent.
        $code = Artisan::call('ai:run-manifest', ['--organization' => $this->organization->slug, '--run' => $runId, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);
        $lecture = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(3, $lecture['turns']);
        $this->assertSame([AiExecutionPath::LOOP_CHAT_DOSSIERS, AiExecutionPath::LOOP_CHAT_DOSSIERS, AiExecutionPath::LOOP_CHAT_DOSSIERS], array_column($lecture['turns'], 'execution_path'));
        $this->assertSame(['answered', 'answered', 'answered'], array_column($lecture['turns'], 'status'));
        $this->assertSame([$runId, $runId, $runId], array_column($lecture['turns'], 'turn_run_id'));
        $this->assertTrue($lecture['fallback_query']['consistent']);
        $this->assertEqualsCanonicalizing(array_column($lecture['turns'], 'interaction_id'), $lecture['fallback_query']['interaction_ids']);
    }

    public function test_b2_un_manifeste_browser_sans_cle_json_se_lit_depuis_le_fichier_seul_et_l_ecart_est_dit(): void
    {
        $runId = $this->runId();
        // Un run navigateur : les tours ont ete produits sans executeur dans le
        // processus -> aucun `turn.run` ; le manifeste seul les nomme.
        $t1 = $this->tour();
        $t2 = $this->tour();
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_BROWSER, (string) $this->organization->id);
        AiRunManifest::addTurn($runId, ['turn_id' => $t1->metadata['turn']['id'], 'interaction_id' => (string) $t1->id]);
        AiRunManifest::addTurn($runId, ['turn_id' => $t2->metadata['turn']['id'], 'interaction_id' => (string) $t2->id]);

        $code = Artisan::call('ai:run-manifest', ['--organization' => $this->organization->slug, '--run' => $runId, '--json' => true]);
        $lecture = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);
        $this->assertSame('browser', $lecture['run_kind']);
        $this->assertCount(2, $lecture['turns']);
        $this->assertSame([null, null], array_column($lecture['turns'], 'turn_run_id'), 'aucun bloc ne porte le run');
        // L'ecart manifeste ↔ blocs est RAPPORTE, pas reconcilie.
        $this->assertFalse($lecture['fallback_query']['consistent']);
        $this->assertCount(2, $lecture['fallback_query']['only_in_manifest']);
        $this->assertSame([], $lecture['fallback_query']['interaction_ids']);
    }

    public function test_b3_le_manifeste_ne_porte_ni_texte_ni_secret(): void
    {
        $runId = $this->runId();
        Artisan::call('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email, '--loop' => (string) $this->loop->id,
            '--mode' => 'dossiers', '--question' => 'QUESTION SECRETE DU MEMBRE ?', '--run-id' => $runId, '--json' => true,
        ]);

        $brut = (string) File::get(AiRunManifest::path($runId));
        $this->assertStringNotContainsString('QUESTION SECRETE', $brut);
        $this->assertStringNotContainsString('SECRET CONTENU', $brut);
        $this->assertStringNotContainsString('sk-test-1583', $brut);
        $this->assertStringNotContainsString('Le document dit ceci', $brut);
        $this->assertSame(
            ['schema', 'run_id', 'run_kind', 'lab_scenario_key', 'organization_id', 'started_at', 'ended_at', 'turns', 'tool_versions'],
            array_keys(json_decode($brut, true)),
        );
        $this->assertSame(['order', 'turn_id', 'interaction_id', 'loop_message_id', 'shell_message_id', 'recorded_at'], array_keys(json_decode($brut, true)['turns'][0]));
    }

    // ────────────────────────────── C. gardes

    public function test_c1_un_manifeste_corrompu_est_refuse_explicitement(): void
    {
        $runId = $this->runId();
        File::ensureDirectoryExists(dirname(AiRunManifest::path($runId)));
        File::put(AiRunManifest::path($runId), '{"run_id":"autre","turns":"pas-une-liste"}');

        $this->artisan('ai:run-manifest', ['--organization' => $this->organization->slug, '--run' => $runId, '--json' => true])
            ->expectsOutputToContain('Manifeste corrompu')->assertExitCode(1);
        // Et un executeur qui voudrait l'ouvrir echoue de la meme facon : jamais ecrase.
        $this->expectException(\RuntimeException::class);
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_CLI, (string) $this->organization->id);
    }

    public function test_c2_un_run_appartient_a_une_organization(): void
    {
        $runId = $this->runId();
        AiRunManifest::start($runId, AiTurnTrace::RUN_KIND_CLI, (string) $this->organization->id);
        $ailleurs = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1583']);

        // Relu depuis une autre Organization : introuvable ici, rien de plus.
        $this->artisan('ai:run-manifest', ['--organization' => $ailleurs->slug, '--run' => $runId, '--json' => true])
            ->expectsOutputToContain('Aucun manifeste pour ce run dans cette Organization')->assertExitCode(1);
        // Rouvert depuis une autre Organization par l'executeur : refuse.
        $etranger = User::factory()->complete()->create(['organization_id' => $ailleurs->id]);
        OrganizationAiSetting::factory()->create(['organization_id' => $ailleurs->id, 'provider' => 'openrouter', 'model' => 'm', 'api_key' => 'k']);
        $loopEtrangere = Loop::factory()->create(['organization_id' => $ailleurs->id, 'created_by' => $etranger->id]);
        $this->artisan('ai:inspect-turn', [
            '--organization' => $ailleurs->slug, '--user' => $etranger->email, '--loop' => (string) $loopEtrangere->id,
            '--mode' => 'dossiers', '--question' => 'Q ?', '--run-id' => $runId, '--json' => true,
        ])->expectsOutputToContain('appartient pas')->assertExitCode(1);
        $this->assertSame(0, AiInteraction::query()->count(), 'refuse AVANT toute execution');
        // Non-uuid.
        $this->artisan('ai:run-manifest', ['--organization' => $this->organization->slug, '--run' => 'pas-un-uuid', '--json' => true])->assertExitCode(1);
    }

    // ────────────────────────────── fixtures

    private function runId(): string
    {
        $id = (string) Str::uuid();
        $this->runs[] = $id;

        return $id;
    }

    private function tour(): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        app(\App\Services\Ai\LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    /** @return array<string, mixed> */
    private function expliquer(AiInteraction $interaction): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $interaction->id, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }
}
