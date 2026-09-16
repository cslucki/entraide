<?php

namespace Tests\Feature\AiLab;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTurnExecutor;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use App\Support\AiLab\LabPreconditions;
use App\Support\AiLab\LabRunner;
use App\Support\AiLab\LabScenario;
use App\Support\AiLab\LabVerdict;
use App\Support\ScenarioPacks\Packs\AiLabPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1591 / CDC-NIGHT L-C_CORE — `ai:lab:run` : un scenario, un run, un
 * verdict, par les VRAIS services (pack charge et indexe par le chemin
 * produit, `Embeddings::fake`, agents fakes ; seule la requete pgvector est
 * remplacee par le chunk REEL du corpus).
 *
 *   A. PASS / FAIL / UNAVAILABLE et `first_failed_component`
 *   B. publication Option B : bulle IA dans la Boucle du Lab, liee par
 *      `metadata.ai_interaction_id`, manifeste `run_kind = lab`
 *   C. `executeForLab` est HARD-BOUND a l'Organization `ai-lab` chargee
 *   D. negatifs tenant / ACL : PASS sans message, sans tour, sans fuite
 *   E. multi-tour : reply a la bulle precedente, derives d'historique
 */
#[Group('ai')]
class TASK1591LabRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $lab;

    /** @var list<string> */
    private array $reponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(AiLabPack::DISK);
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();
        app()->useStoragePath(sys_get_temp_dir().'/lab-1591-'.Str::random(8));

        // L'Organization est pre-creee pour porter la cle et la porte
        // semantique AVANT le chargement (le pack n'ecrit jamais de cle).
        $this->lab = Organization::factory()->create(['slug' => AiLabPack::ORGANIZATION_SLUG, 'name' => 'AI Lab', 'locale' => 'fr', 'loops_enabled' => true, 'is_active' => true]);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->lab->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-lab-1591']);
        config([
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->lab->id],
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => config('database.default') === 'pgsql' ? 1536 : 8,
            'ai_pricing.overrides' => [], 'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.chatloop.min_summary_words' => 0,
        ]);
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (int $i): array => array_fill(0, $prompt->dimensions, ($i + 1) / 10), array_keys($prompt->inputs)))->preventStrayEmbeddings();

        $this->reponses = ['La responsable du projet Helios est Nadia Ferreira [S1].'];
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse(array_shift($this->reponses) ?? 'Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse(array_shift($this->reponses) ?? 'Une reponse directe.'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app()->storagePath());
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. verdicts

    public function test_a1_un_scenario_positif_passe_par_les_vrais_services_et_rend_pass(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $this->assertSame(LabVerdict::PASS, $result['result'], json_encode($result['divergences']));
        $this->assertSame(LabPreconditions::YES, $result['PRECONDITIONS_MATCH_EXPECTED']);
        $this->assertNull($result['first_failed_component']);
        $this->assertFalse($result['leak']);
        $this->assertCount(1, $result['turns']);
        $this->assertSame('answered', $result['turns'][0]['status']);
        $this->assertSame('loop_chat.dossiers', $result['turns'][0]['execution_path']);
        $this->assertNotNull($result['turns'][0]['turn_id']);
        $this->assertSame([], $result['divergences']);
    }

    public function test_a2_une_reponse_qui_ne_dit_pas_le_fait_attendu_est_un_fail_a_la_generation(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        $this->reponses = ['Le projet Helios est pilote par quelqu\'un [S1].'];

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $this->assertSame(LabVerdict::FAIL, $result['result']);
        $this->assertSame('generation', $result['first_failed_component']);
        $this->assertSame(['generation'], $result['divergence_class']);
        $this->assertSame('answer.assertion', $result['divergences'][0]['field']);
        // Le tour a EU LIEU : un FAIL est un resultat, la trace existe.
        $this->assertSame(1, AiInteraction::query()->where('organization_id', $this->lab->id)->count());
    }

    public function test_a3_un_pipeline_qui_retrouve_le_mauvais_dossier_echoue_au_retrieval_avant_la_generation(): void
    {
        $this->chargerLePack();
        // La recherche rend un chunk REEL mais de L4 (le decoy) : la reponse
        // dit pourtant le fait attendu -> premier composant fautif = retrieval.
        $this->rechercheRendLeChunkReel('L4');

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $this->assertSame(LabVerdict::FAIL, $result['result']);
        $this->assertSame('retrieval', $result['first_failed_component']);
        $this->assertSame('sources.must_use_dossier', $result['divergences'][0]['field']);
        $this->assertSame('L4', $result['divergences'][0]['actual']);
    }

    public function test_a4_sans_pack_charge_le_pipeline_n_est_pas_juge_unavailable_et_rien_n_est_execute(): void
    {
        $this->rechercheRendUnChunkFictif();

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $this->assertSame(LabVerdict::UNAVAILABLE, $result['result']);
        $this->assertSame(LabPreconditions::UNAVAILABLE, $result['preconditions']['checks']['pack_loaded']['status']);
        $this->assertSame([], $result['turns']);
        $this->assertSame(0, AiInteraction::query()->count(), 'aucun tour, aucun provider');
        $this->assertSame(0, LoopMessage::query()->count());
        // Le run a quand meme son manifeste (une ligne de matrice = un run_id).
        $this->assertSame(AiTurnTrace::RUN_KIND_LAB, AiRunManifest::load($result['run_id'])['run_kind']);
    }

    public function test_a5_un_chunk_derive_dans_le_lab_rend_la_precondition_no_et_le_composant_data(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        $chunk = $this->chunkReel('L1');
        // Un chunk de connaissance derivee (knowledge:derive-due) : le Lab
        // n'est pas propre — la matrice ne doit pas juger ce pipeline.
        $derive = $chunk->replicate();
        $derive->id = (string) Str::uuid();
        $derive->dossier_file_id = null;
        $derive->derived_knowledge_note_id = DerivedKnowledgeNote::create([
            'organization_id' => $this->lab->id, 'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION, 'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $this->loop('L1')->id, 'dossier_id' => $chunk->dossier_id, 'subject_key' => 'helios', 'content' => 'Une connaissance derivee.',
            'source_fingerprint' => hash('sha256', 'helios'), 'provenance' => ['source_loop_message_ids' => [], 'derived_by' => 'test'],
            'observed_at' => now(), 'derived_at' => now(), 'version' => 1, 'status' => 'active',
        ])->id;
        $derive->content_hash = sha1('derive');
        $derive->save();

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $this->assertSame(LabVerdict::UNAVAILABLE, $result['result']);
        $this->assertSame(LabVerdict::COMPONENT_DATA, $result['first_failed_component']);
        $this->assertSame(LabPreconditions::NO, $result['preconditions']['checks']['derived_chunks_absent']['status']);
        $this->assertSame(0, AiInteraction::query()->count(), 'un Lab sale n\'execute rien');
    }

    // ────────────────────────────── B. publication Option B

    public function test_b1_le_tour_du_lab_est_publie_dans_la_boucle_et_lie_par_ai_interaction_id(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');

        $result = $this->runner()->run($this->scenario('LAB.POSITIVE_SIMPLE_1'));

        $loop = $this->loop('L1');
        $humain = LoopMessage::query()->where('loop_id', $loop->id)->where('type', 'user')->where('metadata->lab_scenario_key', 'LAB.POSITIVE_SIMPLE_1')->first();
        $this->assertNotNull($humain, 'le message humain du tour est dans la Boucle du Lab');
        $this->assertSame((string) $humain->id, $result['turns'][0]['message_id']);

        $interaction = AiInteraction::query()->sole();
        $bulle = LoopMessage::query()->where('loop_id', $loop->id)->where('type', 'ai')->where('metadata->ai_interaction_id', (string) $interaction->id)->first();
        $this->assertNotNull($bulle, 'la bulle IA est publiee et liee au tour');
        $this->assertSame((string) $bulle->id, $result['turns'][0]['bubble_id']);
        $this->assertSame((string) $interaction->id, $result['turns'][0]['interaction_id']);

        $manifeste = AiRunManifest::load($result['run_id']);
        $this->assertSame(AiTurnTrace::RUN_KIND_LAB, $manifeste['run_kind']);
        $this->assertSame('LAB.POSITIVE_SIMPLE_1', $manifeste['lab_scenario_key']);
        $this->assertSame((string) $this->lab->id, $manifeste['organization_id']);
        $this->assertSame((string) $bulle->id, $manifeste['turns'][0]['loop_message_id']);
        $this->assertSame((string) $interaction->id, $manifeste['turns'][0]['interaction_id']);
        $turn = $interaction->metadata['turn'];
        $this->assertSame(AiTurnTrace::RUN_KIND_LAB, $turn['run']['kind']);
        $this->assertSame('LAB.POSITIVE_SIMPLE_1', $turn['run']['lab_scenario_key']);
    }

    // ────────────────────────────── C. hard-bound

    public function test_c1_execute_for_lab_refuse_toute_organization_qui_n_est_pas_le_lab_charge(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        $executor = app(AiTurnExecutor::class);
        $labUser = User::query()->where('email', AiLabPack::emailFor('lab.member.a'))->firstOrFail();
        $loop = $this->loop('L1');
        $trigger = LoopMessage::query()->where('loop_id', $loop->id)->where('type', 'user')->firstOrFail();

        // Une autre Organization, meme dans l'allowlist, meme avec un pack charge.
        $autre = Organization::factory()->create(['slug' => 'pas-le-lab', 'is_active' => true, 'loops_enabled' => true]);
        config(['scenario_packs.allowed_organizations' => [...config('scenario_packs.allowed_organizations'), 'pas-le-lab']]);
        ScenarioPackLoad::query()->create(['organization_id' => $autre->id, 'pack_id' => AiLabPack::PACK_ID, 'pack_version' => '0', 'loaded_at' => now()]);
        $refus = 0;
        try {
            $executor->executeForLab($autre, $labUser, $loop, 'dossiers', 'Q ?', $trigger, (string) Str::uuid(), 'LAB.X_1');
        } catch (\InvalidArgumentException) {
            $refus++;
        }

        // Le Lab lui-meme, mais hors allowlist.
        config(['scenario_packs.allowed_organizations' => ['pas-le-lab']]);
        try {
            $executor->executeForLab($this->lab, $labUser, $loop, 'dossiers', 'Q ?', $trigger, (string) Str::uuid(), 'LAB.X_1');
        } catch (\InvalidArgumentException) {
            $refus++;
        }

        // Le Lab, allowlist OK, mais pack NON charge.
        config(['scenario_packs.allowed_organizations' => [AiLabPack::ORGANIZATION_SLUG]]);
        ScenarioPackLoad::query()->where('organization_id', $this->lab->id)->delete();
        try {
            $executor->executeForLab($this->lab, $labUser, $loop, 'dossiers', 'Q ?', $trigger, (string) Str::uuid(), 'LAB.X_1');
        } catch (\InvalidArgumentException) {
            $refus++;
        }

        // Une cle de scenario hors motif.
        ScenarioPackLoad::query()->create(['organization_id' => $this->lab->id, 'pack_id' => AiLabPack::PACK_ID, 'pack_version' => '0', 'loaded_at' => now()]);
        try {
            $executor->executeForLab($this->lab, $labUser, $loop, 'dossiers', 'Q ?', $trigger, (string) Str::uuid(), 'nimporte quoi');
        } catch (\InvalidArgumentException) {
            $refus++;
        }

        $this->assertSame(4, $refus, 'quatre refus, AVANT toute execution');
        $this->assertSame(0, AiInteraction::query()->count());
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->where('metadata->ai_interaction_id', '!=', '')->count());
    }

    public function test_c2_execute_reste_publish_false_meme_pour_le_lab(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        $labUser = User::query()->where('email', AiLabPack::emailFor('lab.member.a'))->firstOrFail();
        $loop = $this->loop('L1');
        $avant = LoopMessage::query()->where('loop_id', $loop->id)->count();

        $execution = app(AiTurnExecutor::class)->execute($this->lab, $labUser, $loop, 'dossiers', 'Qui est responsable du projet Helios ?', null, null, AiTurnTrace::RUN_KIND_CLI);

        $this->assertFalse($execution->refused());
        $this->assertNull($execution->publishedMessage);
        $this->assertSame($avant, LoopMessage::query()->where('loop_id', $loop->id)->count(), 'execute() ne publie JAMAIS');
    }

    // ────────────────────────────── D. negatifs

    public function test_d1_l_outsider_et_le_membre_hors_boucle_sont_refuses_en_surface_sans_tour_ni_fuite(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        $autre = Organization::factory()->create(['slug' => 'sentinel-b', 'name' => 'SENTINEL-B', 'is_active' => true]);
        $outsider = User::factory()->complete()->create(['organization_id' => $autre->id, 'email' => 'outsider@sentinel-b.test']);
        config(['scenario_packs.lab_outsider_email' => $outsider->email]);
        $messagesAvant = LoopMessage::query()->count();

        $tenant = $this->runner()->run($this->scenario('LAB.TENANT_NEGATIVE_1'));
        $acl = $this->runner()->run($this->scenario('LAB.LOOP_ACL_1'));

        foreach ([$tenant, $acl] as $result) {
            $this->assertSame(LabVerdict::PASS, $result['result'], json_encode($result));
            $this->assertSame(LabPreconditions::YES, $result['PRECONDITIONS_MATCH_EXPECTED']);
            $this->assertSame('denied', $result['preconditions']['checks']['gold_access']['actual']);
            $this->assertFalse($result['leak']);
            $this->assertTrue($result['turns'][0]['refused']);
            $this->assertTrue($result['turns'][0]['refused_before_run']);
            $this->assertNull($result['turns'][0]['message_id']);
        }
        $this->assertSame(0, AiInteraction::query()->count(), 'aucun tour');
        $this->assertSame($messagesAvant, LoopMessage::query()->count(), 'aucun message ecrit dans la Boucle');
    }

    public function test_d2_un_acces_attendu_denied_mais_observe_allowed_est_un_fail_data(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');
        // Le contrat declare `lab.member.c` HORS de L2 ; un banc qui l'y a
        // ajoute contredit la fixture : la precondition negative ne passe pas.
        $c = User::query()->where('email', AiLabPack::emailFor('lab.member.c'))->firstOrFail();
        $this->loop('L2')->members()->create(['user_id' => $c->id, 'role' => 'member', 'status' => 'active']);

        $result = $this->runner()->run($this->scenario('LAB.LOOP_ACL_1'));

        $this->assertSame(LabVerdict::UNAVAILABLE, $result['result']);
        $this->assertSame(LabVerdict::COMPONENT_DATA, $result['first_failed_component']);
        $this->assertSame(LabPreconditions::NO, $result['preconditions']['checks']['gold_access']['status']);
        $this->assertSame(0, AiInteraction::query()->count());
    }

    // ────────────────────────────── E. multi-tour

    public function test_e1_le_tour_2_repond_a_la_bulle_ia_precedente_et_voit_l_historique(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L2');
        $this->reponses = ['Bob Okafor est responsable de l\'integration technique [S1].', 'Alice Martin coordonne les echanges avec les partenaires [S1].'];

        $result = $this->runner()->run($this->scenario('LAB.MULTITURN_REFERENT_1'));

        $this->assertSame(LabVerdict::PASS, $result['result'], json_encode($result['divergences']));
        $this->assertCount(2, $result['turns']);
        $humain2 = LoopMessage::query()->findOrFail($result['turns'][1]['message_id']);
        $this->assertSame($result['turns'][0]['bubble_id'], (string) $humain2->reply_to_id, 'reply_to = previous_ai : la bulle du tour 1');
        $this->assertSame('YES', $result['turns'][1]['history_derived']['PREVIOUS_AI_ANSWER_VISIBLE']);
        $this->assertSame('YES', $result['turns'][1]['history_derived']['PREVIOUS_USER_MESSAGE_VISIBLE']);
        $this->assertSame('NO', $result['turns'][1]['history_derived']['MODE_CHANGED']);
        $this->assertNotNull($result['comparison']);
        $this->assertNull($result['comparison']['first_divergent_step']);
        $this->assertCount(2, AiRunManifest::load($result['run_id'])['turns']);
    }

    // ────────────────────────────── F. commande

    public function test_f1_la_commande_est_une_enveloppe_du_runner_et_rend_le_json_du_run(): void
    {
        $this->chargerLePack();
        $this->rechercheRendLeChunkReel('L1');

        $this->artisan('ai:lab:run', ['--scenario' => 'LAB.INEXISTANT_1'])->assertExitCode(2);
        $this->artisan('ai:lab:run')->assertExitCode(2);
        $this->assertSame(0, AiInteraction::query()->count());

        $this->assertSame(0, Artisan::call('ai:lab:run', ['--scenario' => 'LAB.POSITIVE_SIMPLE_1', '--json' => true]));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame('PASS', $json['result']);
        $this->assertSame('LAB.POSITIVE_SIMPLE_1', $json['lab_scenario_key']);
        $this->assertSame(1, AiInteraction::query()->count());
        // Le JSON est une projection : jamais d'inspection brute, ids seulement.
        $this->assertArrayNotHasKey('inspection', $json['turns'][0]);
        $this->assertArrayNotHasKey('projection', $json['turns'][0]);
    }

    // ────────────────────────────── fixtures

    private function chargerLePack(): void
    {
        $this->assertSame(0, $this->artisan('scenario-pack:load', ['pack' => AiLabPack::PACK_ID, 'organization' => AiLabPack::ORGANIZATION_SLUG])->run());
        $this->lab->refresh();
    }

    private function runner(): LabRunner
    {
        return app(LabRunner::class);
    }

    private function scenario(string $key): LabScenario
    {
        $scenario = LabScenario::find($key);
        $this->assertNotNull($scenario);

        return $scenario;
    }

    private function loop(string $key): Loop
    {
        return Loop::query()->withoutGlobalScopes()->where('organization_id', $this->lab->id)->where('name', AiLabPack::LOOPS[$key]['name'])->firstOrFail();
    }

    private function chunkReel(string $loopKey): DossierChunk
    {
        $dossierIds = Dossier::query()->withoutGlobalScopes()->where('loop_id', $this->loop($loopKey)->id)->pluck('id');
        $file = DossierFile::query()->whereIn('dossier_id', $dossierIds)->where('original_name', AiLabPack::CORPUS[$loopKey][0])->firstOrFail();

        return DossierChunk::query()->where('dossier_file_id', $file->id)->orderBy('chunk_index')->firstOrFail();
    }

    /** La requete pgvector est remplacee par le chunk REEL (indexe) du corpus de la Loop. */
    private function rechercheRendLeChunkReel(string $loopKey): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturnUsing(function () use ($loopKey): array {
            $chunk = $this->chunkReel($loopKey);
            $file = DossierFile::query()->findOrFail($chunk->dossier_file_id);

            return [[
                'chunk_id' => (string) $chunk->id, 'dossier_id' => (string) $chunk->dossier_id, 'dossier_name' => Dossier::query()->withoutGlobalScopes()->find($chunk->dossier_id)?->name, 'source_type' => 'file',
                'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) $file->id, 'filename' => $file->original_name,
                'mime_type' => $file->mime_type, 'chunk_index' => $chunk->chunk_index, 'content' => $chunk->content, 'distance' => 0.2,
            ]];
        });
    }

    private function rechercheRendUnChunkFictif(): void
    {
        $mock = $this->mock(DossierSemanticSearchService::class);
        $mock->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchAcrossDossiers')->andReturn([])->byDefault();
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }
}
