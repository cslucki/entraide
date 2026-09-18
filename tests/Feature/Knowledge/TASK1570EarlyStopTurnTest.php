<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Ai\OrganizationAiConsumption;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiQualityReport;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Ai\RecordsAiConsumption;
use Tests\TestCase;

/**
 * TASK-1570 / CDC-01 V0-B — un arret anticipe laisse un tour.
 *
 * ## Ce que ce fichier garde
 *
 *  A. chaque arret anticipe ECRIT une `AiInteraction` non generative portant
 *     son bloc `turn` (statut, etage, code, decideur) — abstention zero-source,
 *     refus economique, provider non configure — et la reponse vide post-appel
 *     s'ecrit `failed` / `EMPTY_MODEL_ANSWER` avec sa ligne ledger REELLE ;
 *  B. le contrat de la ligne (CDC-01 §6.1) : `response` null, tokens 0,
 *     `cost_usd = 0` CONNU, ledger vierge, `model`/`prompt` vides quand rien
 *     n'a ete resolu, `retrieval_trace` reclamee et PERSISTEE ;
 *  C. l'audit des LECTEURS (§6.3), mesure : un refus ne consomme pas un credit,
 *     n'entre pas dans le budget, ne compte pas comme interaction evaluable, et
 *     s'affiche dans `/profile/ai-usage` comme un tour — jamais comme une
 *     generation, sans cout, sans token (arbitrage A7) ;
 *  D. le produit ne change pas : memes refus, memes messages, rien de publie ;
 *     et l'Inspector lit ce tour sans le rejouer (V0-H0).
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1570EarlyStopTurnTest extends TestCase
{
    use RecordsAiConsumption;
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    private Loop $loop;

    private Dossier $dossier;

    /** La recherche rend-elle une source ? `false` = abstention zero-source. */
    private bool $rechercheRend = true;

    /** Les lignes qui existaient AVANT le tour sous test (mises en place du harnais). */
    private array $existants = [];

    private int $ledgerAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1570']);

        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1570',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle des arrets');

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->membre->id,
            'name' => 'Dossier de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
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
            'ai.chatloop.min_summary_words' => 0,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing(fn (): array => $this->rechercheRend ? [$this->ligne()] : [])->byDefault();

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('Le document dit ceci [S1].'));
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse('Une reponse directe.'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. chaque arret laisse un tour

    public function test_a1_l_abstention_zero_source_laisse_un_tour_abstained_et_persiste_sa_retrieval_trace(): void
    {
        $this->sansAucuneProvenance();
        LoopKnowledgeAgent::fake([]);

        $answer = app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);

        // Le produit : meme message, aucune interaction liee au DTO (LoopChat
        // lit `interactionId === null` pour prevenir l'auteur).
        $this->assertSame(__('loops.knowledge_no_sources'), $answer->answer);
        $this->assertNull($answer->interactionId);
        LoopKnowledgeAgent::assertNeverPrompted();

        $tour = $this->tourUnique();
        $this->assertNonGenerative($tour, AiTurnState::TURN_ABSTAINED, 'grounding', AiTurnReason::TERMINAL_NO_SOURCES_FOUND);
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $tour->metadata['turn']['identity']['execution_path']);
        $this->assertSame('LoopKnowledgeAnswerService', $tour->metadata['turn']['decided_by']);

        // La trace de retrieval n'est plus jetee (limite T1565 levee).
        $this->assertArrayHasKey(DossierRetrievalTraceRecorder::TURN_METADATA_KEY, $tour->metadata);
        $this->assertIsArray($tour->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['dossier_retrieval']);
        $this->assertSame(0, $tour->metadata[DossierRetrievalTraceRecorder::TURN_METADATA_KEY]['dossier_retrieval']['dense_candidates_count']);

        // Les etapes traversees, dans l'ordre, jusqu'a l'etage qui a decide.
        $etapes = $tour->metadata['turn']['steps'];
        // V0-F : `retrieval`/`rerank` sont deposes par la source pendant le builder.
        $this->assertSame(['economic_check', 'retrieval', 'rerank', 'context_builder', 'grounding'], array_column($etapes, 'name'));
        $this->assertSame('abstained', end($etapes)['status']);
    }

    public function test_a2_le_refus_economique_laisse_un_tour_refused_avec_le_code_du_garde(): void
    {
        $this->creditEpuise();

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
            $this->fail('un refus etait attendu');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $e->refusalCode);
        }

        $tour = $this->tourUnique();
        $this->assertNonGenerative($tour, AiTurnState::TURN_REFUSED, 'economic_check', AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED);
        // Le modele ETAIT resolu : il est ecrit tel quel, pas invente.
        $this->assertSame('openrouter/openai/gpt-4o-mini', $tour->model);
        $this->assertSame('openrouter', $tour->metadata['provider']);
        $this->assertSame([['name' => 'economic_check', 'status' => 'denied', 'reason_code' => AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED]], $tour->metadata['turn']['steps']);
    }

    public function test_a3_le_provider_non_configure_laisse_un_tour_refused_sans_modele_invente(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
            $this->fail('un refus etait attendu');
        } catch (AiRefusedException $e) {
            $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, $e->refusalCode);
        }

        $tour = $this->tourUnique();
        $this->assertNonGenerative($tour, AiTurnState::TURN_REFUSED, 'provider_resolution', AiRefusedException::CODE_NOT_CONFIGURED);
        // Rien n'a ete resolu : colonnes NOT NULL a vide, JAMAIS une valeur.
        $this->assertSame('', $tour->model);
        $this->assertSame('', $tour->prompt);
        $this->assertArrayNotHasKey('provider', $tour->metadata);
    }

    public function test_a4_la_reponse_vide_post_appel_s_ecrit_failed_avec_sa_ligne_ledger_reelle(): void
    {
        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('   '));

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
            $this->fail('une reponse vide etait attendue');
        } catch (RuntimeException $e) {
            $this->assertSame(__('loops.ai_empty_response'), $e->getMessage());
        }

        $tour = $this->tourUnique();
        $this->assertSame('failed', $tour->metadata['status']);
        $this->assertNull($tour->response);
        $this->assertSame(AiTurnState::TURN_FAILED, $tour->metadata['turn']['status']);
        $this->assertSame('generation', $tour->metadata['turn']['stage']);
        $this->assertSame(AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $tour->metadata['turn']['reason_code']);
        // L'appel EST parti : il se paie, et le ledger le dit (trou S11 ferme).
        $this->assertSame(20, $tour->input_tokens);
        $this->assertSame(1, AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_GENERATION)->count());
        $this->assertSame('failed', $this->etape($tour, 'generation')['status']);
    }

    public function test_a5_le_mode_ia_et_les_chemins_herites_laissent_aussi_leur_tour(): void
    {
        $this->creditEpuise();
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour', 'type' => 'text', 'organization_id' => $this->organization->id]);

        foreach ([
            AiExecutionPath::LOOP_CHAT_IA => fn () => app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Capitale ?', $declencheur),
            AiExecutionPath::LOOP_CHAT_LEGACY_ASK => fn () => app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Capitale ?'),
            AiExecutionPath::LOOP_CHAT_LEGACY_ANSWER => fn () => app(ChatLoopAiService::class)->answer($this->loop, $this->membre),
        ] as $chemin => $tour) {
            $deja = AiInteraction::query()->pluck('id')->all();
            AiTurnLock::forgetRequestState();

            try {
                $tour();
                $this->fail("un refus etait attendu sur `{$chemin}`");
            } catch (AiRefusedException) {
            }

            $ligne = AiInteraction::query()->whereNotIn('id', $deja)->sole();
            $this->assertNonGenerative($ligne, AiTurnState::TURN_REFUSED, 'economic_check', AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED);
            $this->assertSame($chemin, $ligne->metadata['turn']['identity']['execution_path']);
        }

        // Le mode IA porte l'historique qu'il a VU, meme sur un refus.
        $ia = AiInteraction::query()->where('metadata->turn->identity->execution_path', AiExecutionPath::LOOP_CHAT_IA)->sole();
        $this->assertSame('reply_chain', $ia->metadata['turn']['history']['strategy']);
        LoopDirectAnswerAgent::assertNeverPrompted();
    }

    public function test_a6_une_reponse_vide_du_mode_ia_s_ecrit_failed_et_non_success(): void
    {
        LoopDirectAnswerAgent::fake(fn (): TextResponse => $this->reponse(''));
        $declencheur = LoopMessage::create(['loop_id' => $this->loop->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour', 'type' => 'text', 'organization_id' => $this->organization->id]);

        try {
            app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Capitale ?', $declencheur);
            $this->fail('une reponse vide etait attendue');
        } catch (RuntimeException $e) {
            $this->assertSame(__('loops.ai_empty_response'), $e->getMessage());
        }

        $tour = $this->tourUnique();
        $this->assertSame('failed', $tour->metadata['status']);
        $this->assertSame(AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $tour->metadata['turn']['reason_code']);
        $this->assertSame(AiTurnState::TURN_FAILED, $tour->metadata['turn']['status']);
        $this->assertSame(0, LoopMessage::query()->where('type', 'ai')->count(), 'rien n\'est publie');
    }

    // ────────────────────────────── C. les lecteurs, mesures

    /**
     * LE garde economique de cette TASK.
     *
     * Deux autorites, deux mesures : le CREDIT lit le ledger depuis le cutover
     * (il ne bouge pas parce que le ledger reste vierge) ; la CONSOMMATION lit
     * `ai_interactions` et doit EXCLURE la ligne non generative. Sabotage
     * verifie : retirer l'exclusion de `OrganizationAiConsumption::baseQuery()`
     * laisse `used` intact (FACT : le credit n'etait pas menace) mais fait
     * compter le refus comme une generation mesuree → ce test rougit.
     */
    public function test_c1_un_refus_ne_consomme_pas_un_credit_et_ne_compte_pas_comme_consommation(): void
    {
        $this->creditEpuise();
        $guard = app(AiEconomicGuard::class);
        $consommation = app(OrganizationAiConsumption::class);
        $fenetre = new AiConsumptionFilters(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->addDay());
        $avant = $guard->userCreditStatus($this->organization, $this->membre)->used;
        $consoAvant = $consommation->summary((string) $this->organization->id, $fenetre);

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        } catch (AiRefusedException) {
        }

        $this->tourUnique();
        $this->assertSame($avant, $guard->userCreditStatus($this->organization, $this->membre)->used, 'le refus a consomme un credit');
        $this->assertSame($this->ledgerAvant, AiProviderInvocation::query()->count(), 'ledger vierge (I3)');

        $consoApres = $consommation->summary((string) $this->organization->id, $fenetre);
        $this->assertSame($consoAvant['trace_count'], $consoApres['trace_count'], 'le refus compte comme une generation');
        $this->assertSame($consoAvant['measured_count'], $consoApres['measured_count'], 'le refus compte comme un cout mesure');
        $this->assertSame($consoAvant['known_cost_usd'], $consoApres['known_cost_usd']);

        // Un second refus non plus — et l'abstention non plus.
        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Et ceci ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        } catch (AiRefusedException) {
        }
        $this->assertSame($avant, $guard->userCreditStatus($this->organization, $this->membre)->used);
        $this->assertSame(2, AiInteraction::query()->whereIn('metadata->status', AiTurnState::NON_GENERATIVE_STATUSES)->count());
    }

    public function test_c2_les_tours_non_generatifs_sortent_du_rapport_qualite(): void
    {
        $this->sansAucuneProvenance();
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'loop_knowledge.answer', 'loop_knowledge_answer', 0.001);
        AiTurnLock::forgetRequestState();
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);

        $this->assertSame(2, AiInteraction::query()->where('feature', 'loop_knowledge_answer')->count());

        $rapport = app(AiQualityReport::class)->forOrganization($this->organization, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->addDay());

        $this->assertSame(1, $rapport['interactions'], 'l\'abstention n\'est pas une interaction evaluable');
    }

    public function test_c3_profile_ai_usage_montre_le_tour_comme_un_tour_jamais_comme_une_generation(): void
    {
        $this->creditEpuise();

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        } catch (AiRefusedException) {
        }

        $activite = app(AiProviderInvocationConsole::class)->recentActivityForUser((string) $this->organization->id, (string) $this->membre->id);
        $refus = array_values(array_filter($activite, static fn (array $r): bool => $r['status'] === AiTurnState::TURN_REFUSED));

        $this->assertCount(1, $refus);
        $this->assertSame('turn', $refus[0]['kind']);
        $this->assertSame('not_applicable', $refus[0]['cost_state']);
        $this->assertNull($refus[0]['cost_usd']);

        // Et la page le rend sans planter, avec le libelle du tour et son statut.
        $this->actingAs($this->membre)
            ->get(route('profile.ai-usage'))
            ->assertOk()
            ->assertSee('data-my-ai-usage-kind="turn"', false)
            ->assertSee(__('ai.usage_type_turn'))
            ->assertSee(__('ai.usage_status_refused'));

        // La generation reelle du harnais, elle, reste une generation : les deux
        // se cotoient et se distinguent.
        $this->assertCount(1, array_filter($activite, static fn (array $r): bool => $r['kind'] === 'generation'));
    }

    // ────────────────────────────── D. produit inchange, inspector

    public function test_d1_l_inspector_explique_le_tour_abstenu_sans_le_rejouer(): void
    {
        $this->sansAucuneProvenance();
        app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        $tour = $this->tourUnique();

        LoopKnowledgeAgent::fake(function (): never {
            throw new RuntimeException('EXPLAIN a sollicite un provider');
        });

        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, '--interaction' => (string) $tour->id, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, $sortie);

        $trace = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(AiTurnState::TURN_ABSTAINED, $trace['decision']['status']);
        $this->assertSame('grounding', $trace['decision']['stage']);
        $this->assertSame(AiTurnReason::TERMINAL_NO_SOURCES_FOUND, $trace['decision']['reason_code']);
        $this->assertNull($trace['decision']['latency_ms'], 'aucun chrono invente');
        $this->assertNotNull($trace['retrieval_trace']);
        $this->assertNull($trace['output']['response']);
    }

    public function test_d2_le_composeur_garde_exactement_son_comportement_sur_une_abstention(): void
    {
        $this->sansAucuneProvenance();
        $messages = LoopMessage::query()->count();

        $this->actingAs($this->membre)
            ->postJson(route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]), ['question' => 'Que dit le document ?'])
            ->assertOk()
            ->assertJsonPath('answer', __('loops.knowledge_no_sources'));

        $this->assertSame($messages, LoopMessage::query()->count(), 'rien n\'est publie dans le fil');
        $this->tourUnique();
    }

    // ────────────────────────────── harnais

    private function assertNonGenerative(AiInteraction $tour, string $status, string $stage, string $reasonCode): void
    {
        $this->assertNull($tour->response);
        $this->assertSame(0, $tour->input_tokens);
        $this->assertSame(0, $tour->output_tokens);
        $this->assertSame(0.0, (float) $tour->cost_usd);
        $this->assertFalse($tour->cost_unknown, 'un cout CONNU et nul, pas un cout inconnu');
        $this->assertSame($status, $tour->metadata['status']);
        $this->assertSame($this->ledgerAvant, AiProviderInvocation::query()->count(), 'ledger vierge (I3)');

        $turn = $tour->metadata['turn'];
        $this->assertSame(AiTurnTrace::SCHEMA_VERSION, $turn['schema']);
        $this->assertTrue(Str::isUuid($turn['id']));
        $this->assertSame($status, $turn['status']);
        $this->assertSame($stage, $turn['stage']);
        $this->assertSame($reasonCode, $turn['reason_code']);
        $this->assertArrayHasKey('decided_by', $turn);
        $this->assertArrayNotHasKey('latency_ms', $turn, 'aucun chrono invente pour un arret');
        $this->assertTrue(AiTurnReason::isKnown($reasonCode));
    }

    /** LA ligne que le tour sous test vient d'ecrire — et une seule. */
    private function tourUnique(): AiInteraction
    {
        return AiInteraction::query()->whereNotIn('id', $this->existants)->sole();
    }

    /** @return array<string, mixed> */
    private function etape(AiInteraction $tour, string $nom): array
    {
        foreach ($tour->metadata['turn']['steps'] as $etape) {
            if ($etape['name'] === $nom) {
                return $etape;
            }
        }

        $this->fail("etape `{$nom}` absente");
    }

    private function sansAucuneProvenance(): void
    {
        // Ni retrieval, ni manifest : le Dossier de la Boucle et le document
        // racine sont retires — sinon le manifest seul fournirait un [M1].
        // Le Dossier partage RESTE (sans fichier : aucun [Mn]) pour que le
        // retrieval TOURNE — et rende zero — ; seul le document racine part.
        $this->rechercheRend = false;
        Dossier::query()->where('loop_id', $this->loop->id)->delete();
    }

    private function creditEpuise(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true,
            'monthly_uses' => 1,
            'alert_percent' => 80,
            'offer_subscription' => true,
        ], $this->superAdmin);

        // Une VRAIE generation consommee (les deux autorites) : le credit est
        // a 1/1. Elle reste en base — c'est elle que le refus ne doit pas
        // doubler.
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'chatloop.ask', 'chatloop_ai_ask', 0.001);
        $this->marquer();
    }

    private function marquer(): void
    {
        $this->existants = AiInteraction::query()->pluck('id')->all();
        $this->ledgerAvant = AiProviderInvocation::query()->count();
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
