<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTruthLabel;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1575 / CDC-01 V0-H — « inspect-turn explique LoopChat ».
 *
 * A. truth labels : chaque champ de l'inspection dit d'ou il vient
 *    (MEASURED / DERIVED / DECLARED / UNAVAILABLE) ; un DERIVED ne s'appuie
 *    jamais sur un UNAVAILABLE (§8.2).
 * B. EXECUTE `--mode=ia` : le vrai `respondInThread`, seam `publish: false`
 *    (aucune bulle ecrite, l'`AiInteraction` du tour se lit).
 * C. minors H0 : un id non-uuid est refuse proprement.
 * D. le produit ne bouge pas : `respondInThread()` publie par defaut.
 */
#[Group('ai')]
class TASK1575ExplainTruthLabelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1575']);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1575',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle expliquee 1575');

        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier 1575',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [],
            'ai.chatloop.enabled' => true,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
            'ai.knowledge.retrieval_trace.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $dossier->id, 'dossier_name' => $dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]])->byDefault();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. truth labels

    public function test_a1_un_tour_v0_porte_un_label_par_champ_et_le_bon(): void
    {
        $trace = $this->expliquer(['--interaction' => (string) $this->tourRag()->id]);
        $truth = $trace['truth'];

        // Chaque champ scalaire des sections lues porte un label, et le
        // vocabulaire est ferme.
        foreach (['run', 'identity', 'decision', 'state', 'output', 'provider', 'sources'] as $section) {
            foreach (array_keys($trace[$section]) as $champ) {
                $this->assertArrayHasKey($section.'.'.$champ, $truth, "label manquant : {$section}.{$champ}");
            }
        }
        $this->assertSame([], array_diff(array_values($truth), AiTruthLabel::all()), 'label hors vocabulaire');

        // DECLARED : ce que l'appelant / le registre a dit, pas une observation.
        $this->assertSame(AiTruthLabel::DECLARED, $truth['identity.execution_path']);
        $this->assertSame(AiTruthLabel::DECLARED, $truth['identity.capability']);
        $this->assertSame(AiTruthLabel::DECLARED, $truth['run.capability']);

        // MEASURED : observe pendant le tour.
        $this->assertSame(AiTruthLabel::MEASURED, $truth['identity.provider_effective']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['steps']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['decision.status']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['state.verification_status']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['provider.input_tokens']);

        // Un `null` ECRIT par le writer est une mesure (« rien a signaler »),
        // pas une absence : `degraded_reason: null` sur un tour nominal.
        $this->assertNull($trace['state']['degraded_reason']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['state.degraded_reason']);
        // ... alors qu'un verdict a `reason_code: null` n'est PAS persiste par
        // `compose()` (schema v1) : la cle manque au bloc, le lecteur ne peut
        // pas distinguer « aucune raison » de « jamais ecrit » -> UNAVAILABLE,
        // pas une inference depuis `status = answered`.
        $this->assertNull($trace['decision']['reason_code']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['decision.reason_code']);

        // DERIVED : calcule par le lecteur.
        $this->assertSame(AiTruthLabel::DERIVED, $truth['run.turn_id_source']);
        $this->assertSame(AiTruthLabel::DERIVED, $truth['state.source']);
        $this->assertSame(AiTruthLabel::DERIVED, $truth['state.rule']);
    }

    public function test_a2_un_tour_muet_est_unavailable_et_ses_derives_aussi(): void
    {
        $trace = $this->expliquer(['--interaction' => (string) $this->ligneFabriquee(['status' => 'completed'])->id]);
        $truth = $trace['truth'];

        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['identity']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['steps']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['sources']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['run.turn_id']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['run.turn_id_source']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['decision.reason_code'], 'aucun bloc turn : un null n\'est pas une mesure');

        // Repli `legacy_metadata` : les axes sont CALCULES par le lecteur.
        $this->assertSame('legacy_metadata', $trace['state']['source']);
        $this->assertSame(AiTruthLabel::DERIVED, $truth['state.turn_status']);
        // `grounded` absent -> l'axe 2 n'a rien pour se calculer -> UNAVAILABLE,
        // et la regle R1-R7 qui en depend l'est aussi.
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['output.grounded']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['state.verification_status']);
        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['state.rule']);
    }

    public function test_a3_garde_aucun_derived_ne_s_appuie_sur_un_unavailable(): void
    {
        $inspections = [
            $this->expliquer(['--interaction' => (string) $this->tourRag()->id]),
            $this->expliquer(['--interaction' => (string) $this->ligneFabriquee(['status' => 'completed'])->id]),
            $this->expliquer(['--interaction' => (string) $this->ligneFabriquee(['status' => 'completed', 'grounded' => true, 'sources_denied' => []])->id]),
        ];

        foreach ($inspections as $i => $trace) {
            $truth = $trace['truth'];
            $derivations = AiTurnInspection::derivations($trace);

            foreach ($truth as $cle => $label) {
                if ($label !== AiTruthLabel::DERIVED) {
                    continue;
                }
                $this->assertArrayHasKey($cle, $derivations, "[{$i}] {$cle} est DERIVED sans dependances declarees");
                foreach ($derivations[$cle] as $dependance) {
                    $this->assertNotSame(AiTruthLabel::UNAVAILABLE, $truth[$dependance] ?? AiTruthLabel::UNAVAILABLE,
                        "[{$i}] {$cle} est DERIVED alors que {$dependance} est UNAVAILABLE");
                }
            }
        }
    }

    public function test_a4_un_derive_dont_la_mesure_manque_devient_unavailable(): void
    {
        // Lecteur pris en defaut a la main : `turn_id_source` posee alors que
        // `turn_id` manque. Le label ne croit pas la valeur, il regarde la
        // dependance.
        $truth = AiTurnInspection::truthLabels([
            'run' => ['turn_id' => null, 'turn_id_source' => 'turn.id', 'status' => 'completed'],
            'state' => ['source' => 'turn', 'turn_status' => 'answered', 'verification_status' => 'supported', 'degraded_reason' => null, 'rule' => 'R1'],
        ], ['state' => ['verification_status' => 'supported', 'degraded_reason' => null]]);

        $this->assertSame(AiTruthLabel::UNAVAILABLE, $truth['run.turn_id_source']);
        $this->assertSame(AiTruthLabel::MEASURED, $truth['state.degraded_reason']);
        $this->assertSame(AiTruthLabel::DERIVED, $truth['state.rule']);
    }

    // ────────────────────────────── B. EXECUTE --mode=ia

    public function test_b1_mode_ia_exige_un_declencheur_du_fil(): void
    {
        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email,
            '--loop' => (string) $this->loop->id, '--mode' => 'ia', '--question' => 'Bonjour ?', '--json' => true,
        ])->expectsOutputToContain('--trigger-message')->assertExitCode(1);

        $autreBoucle = Loop::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $this->membre->id]);
        $ailleurs = LoopMessage::create(['loop_id' => $autreBoucle->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Ailleurs', 'type' => 'text']);

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email,
            '--loop' => (string) $this->loop->id, '--mode' => 'ia', '--question' => 'Bonjour ?',
            '--trigger-message' => (string) $ailleurs->id, '--json' => true,
        ])->expectsOutputToContain('introuvable dans cette Boucle')->assertExitCode(1);

        $this->assertSame(0, AiInteraction::query()->count(), 'un refus de la CLI n\'execute rien');
    }

    public function test_b2_mode_ia_execute_le_vrai_chemin_sans_ecrire_de_bulle_et_se_lit(): void
    {
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Quelle heure est-il ?', 'type' => 'text']);
        $bulles = LoopMessage::query()->count();

        LoopDirectAnswerAgent::fake([new TextResponse('Il est midi.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $code = Artisan::call('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email,
            '--loop' => (string) $this->loop->id, '--mode' => 'ia', '--question' => 'Quelle heure est-il ?',
            '--trigger-message' => (string) $declencheur->id, '--json' => true,
        ]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);
        $trace = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);

        // Le seam : le tour a eu lieu (AiInteraction, ledger via le service),
        // la bulle non.
        $this->assertSame($bulles, LoopMessage::query()->count(), 'publish:false n\'ecrit aucune bulle');
        $this->assertSame(1, AiInteraction::query()->count());

        // Et il se LIT comme tout tour persiste, labels compris.
        $this->assertSame('explain', $trace['mode']);
        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $trace['identity']['execution_path']);
        $this->assertSame('answered', $trace['decision']['status']);
        $this->assertSame(AiTruthLabel::DECLARED, $trace['truth']['identity.execution_path']);
        $this->assertSame(AiTruthLabel::MEASURED, $trace['truth']['steps']);
        $this->assertSame((string) AiInteraction::query()->first()->id, $trace['run']['ai_interaction_id']);
    }

    public function test_b3_un_declencheur_deja_repondu_est_refuse_par_le_produit_pas_approxime(): void
    {
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Deja repondu ?', 'type' => 'text']);
        LoopMessage::create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => null, 'reply_to_id' => $declencheur->id, 'body' => 'Oui.', 'type' => 'ai',
            'metadata' => ['ai_mode' => 'llm', 'action' => 'ia', 'trigger_message_id' => $declencheur->id],
        ]);

        LoopDirectAnswerAgent::fake([]);

        $this->artisan('ai:inspect-turn', [
            '--organization' => $this->organization->slug, '--user' => $this->membre->email,
            '--loop' => (string) $this->loop->id, '--mode' => 'ia', '--question' => 'Deja repondu ?',
            '--trigger-message' => (string) $declencheur->id, '--json' => true,
        ])->expectsOutputToContain('"refused": true')->assertExitCode(1);

        LoopDirectAnswerAgent::assertNeverPrompted();
        $this->assertSame(0, AiInteraction::query()->count());
    }

    // ────────────────────────────── C. minors H0

    public function test_c1_un_id_qui_n_est_pas_un_uuid_est_refuse_proprement(): void
    {
        foreach (['--interaction', '--message'] as $cle) {
            $this->artisan('ai:inspect-turn', ['--organization' => $this->organization->slug, $cle => 'pas-un-uuid', '--json' => true])
                ->expectsOutputToContain('doit etre un uuid')
                ->assertExitCode(1);
        }
    }

    // ────────────────────────────── D. le produit ne bouge pas

    public function test_d1_respond_in_thread_publie_par_defaut(): void
    {
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Publie ?', 'type' => 'text']);
        LoopDirectAnswerAgent::fake([new TextResponse('Publie.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $resultat = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Publie ?', $declencheur);

        $this->assertInstanceOf(LoopMessage::class, $resultat);
        $this->assertSame('ai', $resultat->type);
        $this->assertSame((string) $declencheur->id, (string) $resultat->reply_to_id);
    }

    // ────────────────────────────── fixtures

    private function tourRag(): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();

        AiTurnLock::forgetRequestState();
        LoopKnowledgeAgent::fake([new TextResponse('Le document dit ceci [S1].', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $this->actingAs($this->membre)
            ->postJson(route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]), ['question' => 'Que dit le document ?'])
            ->assertOk();

        $nouvelles = AiInteraction::query()->whereNotIn('id', $deja)->get();
        $this->assertCount(1, $nouvelles);

        return $nouvelles->first();
    }

    /** @param  array<string, mixed>  $metadata */
    private function ligneFabriquee(array $metadata): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'correlation_id' => (string) Str::uuid(),
            'process' => 'knowledge.answer',
            'feature' => 'loop_knowledge_answer',
            'model' => 'openrouter/openai/gpt-4o-mini',
            'prompt' => 'prompt',
            'response' => 'reponse',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'metadata' => $metadata,
        ]);
    }

    /** @param  array<string, string>  $cle */
    private function expliquer(array $cle): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, ...$cle, '--json' => true]);
        $sortie = Artisan::output();

        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }
}
