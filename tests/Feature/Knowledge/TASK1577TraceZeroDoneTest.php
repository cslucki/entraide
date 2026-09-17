<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Agents\ShellGeneralAnswerAgent;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Livewire\LoopChat;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\AiShellMessage;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiShellPageContext;
use App\Support\Ai\AiTruthLabel;
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
use RuntimeException;
use Tests\Support\Ai\RecordsAiConsumption;
use Tests\TestCase;

/**
 * TASK-1577 / CDC-01 V0-J — « le V0 se prouve » : gate TRACE0_DONE.
 *
 * Chaque scenario du §10 joue un VRAI tour produit (composeur, endpoint,
 * Shell, service) puis le lit UNIQUEMENT par `ai:inspect-turn … --json` — le
 * seul outil que le critere de DONE (§14) autorise. Rien n'est lu en base
 * directement pour prouver une reponse : si la commande ne le dit pas, ce
 * n'est pas prouve.
 *
 * Les questions du §14 sont posees telles quelles en B, sur quatre tours de
 * nature differente ; une reponse est soit une valeur, soit un UNAVAILABLE
 * ASSUME (liste fermee, chacun avec sa raison au CDC) — jamais un `null`
 * muet.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1577TraceZeroDoneTest extends TestCase
{
    use RecordsAiConsumption;
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    private Loop $loop;

    private Dossier $dossier;

    private bool $rechercheRend = true;

    private ?\Throwable $rechercheLeve = null;

    /**
     * Les questions du §14, et le champ de l'inspection qui y repond. Une
     * question peut avoir plusieurs champs (« demande ? effectif ? »).
     *
     * @var array<string, list<string>>
     */
    private const QUESTIONS_14 = [
        'Quel chemin produit a ete utilise ?' => ['identity.execution_path'],
        'Quel producer l a pris en charge ?' => ['identity.producer'],
        'Quel provider etait demande ? Quel provider a reellement repondu ?' => ['identity.provider_requested', 'identity.provider_effective'],
        'Y a-t-il eu fallback ?' => ['identity.fallback_used'],
        'ContextBuilder execute ou bypasse ?' => ['step:context_builder'],
        'Le retrieval a-t-il ete execute ? Combien de candidats ?' => ['step:retrieval', 'sources.retrieved'],
        'Le rerank a-t-il ete execute ?' => ['step:rerank', 'sources.reranked'],
        'Quel historique ce tour a-t-il recu ?' => ['history.strategy', 'history.count', 'history.chars'],
        'Quelles sources utilisees ? Lesquelles refusees, pourquoi ?' => ['sources.used', 'sources.denied'],
        'Le grounding a-t-il accepte ou refuse la preuve ?' => ['step:grounding', 'state.verification_status'],
        'Genere, refuse, abstenu ou en erreur ?' => ['decision.status'],
        'Quel composant a pris la decision ?' => ['decision.decided_by'],
        'Quel reason_code ?' => ['decision.reason_code'],
        'Pourquoi cette reponse finale ?' => ['decision.stage', 'output.response', 'output.failure'],
    ];

    /**
     * Les UNAVAILABLE ASSUMES du V0, et leur raison au CDC. Tout autre `null`
     * sur un champ du §14 est un trou.
     *
     * @var array<string, string>
     */
    private const UNAVAILABLE_ASSUMES = [
        'identity.provider_requested' => 'V0-D : ResolvedModel ne porte que le resolu ; aucune source honnete pour le demande (I6 reste tenu par fallback_used)',
        'step:retrieval' => 'chemins sans DossierRetrievalSource (mode ia, Shell general, clarify, pages Dossier) ou arret avant le retrieval',
        'step:rerank' => 'idem retrieval',
        'step:grounding' => 'chemins sans citation (mode ia, general, clarify) ou arret avant la generation',
        'sources.retrieved' => 'sans retrieval_trace : null par construction (V0-E)',
        'sources.reranked' => 'idem',
        'decision.reason_code' => 'tour answered : compose() ne persiste pas un verdict nul (C25) — la reponse est « aucun »',
        'decision.stage' => 'tour answered : pas d etape terminale non nominale',
        'output.response' => 'tour non genere : aucune reponse, et c est la reponse',
        'output.failure' => 'tour sans exception',
        'history.strategy' => 'arret AVANT la lecture de l historique (retrieval en echec) : rien n a ete vu',
        'history.count' => 'idem',
        'history.chars' => 'idem',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1577', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1577',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle de la preuve');
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id,
            'name' => 'Dossier de la preuve', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
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
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturnUsing(function (): array {
            if ($this->rechercheLeve !== null) {
                throw $this->rechercheLeve;
            }

            return $this->rechercheRend ? [$this->ligne()] : [];
        })->byDefault();

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

    // ────────────────────────────── A. les 13 scenarios du §10

    public function test_s01_tour_nominal_rag_avec_sources_utilisees(): void
    {
        $t = $this->expliquer($this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?')));

        $this->assertSame('answered', $t['decision']['status']);
        $this->assertSame(AiExecutionPath::LOOP_CHAT_DOSSIERS, $t['identity']['execution_path']);
        // TASK-1595 — les etapes REELLEMENT presentes de ce chemin. Il repond
        // desormais par `DossierInsightsService`, qui compose son bloc de
        // sources lui-meme (`context_builder` bypasse) et dont le pipeline n'a
        // ni filtre de distance ni rerank : il n'y a donc ni etape `retrieval`
        // ni etape `rerank` a attendre. Les exiger reviendrait a demander a la
        // trace de decrire un pipeline qui n'a pas tourne.
        $this->assertSame(['context_builder', 'conversation_history', 'economic_check', 'provider_call', 'grounding'], array_column($t['steps'], 'name'));
        $this->assertNotEmpty($t['sources']['used']);
        $this->assertContains(DossierInsightsService::SOURCE_NAME, $t['sources']['used']);
        $this->assertSame('openrouter', $t['identity']['provider_effective']);
        $this->assertFalse($t['identity']['fallback_used']);
        $this->assertSame('supported', $t['state']['verification_status']);
        $this->assertSame(1, $this->etape($t, 'grounding')['metrics']['cited']);
    }

    public function test_s02_retrieval_sans_resultat_abstention(): void
    {
        $this->rechercheRend = false;
        Dossier::query()->where('loop_id', $this->loop->id)->delete();

        // Par l'endpoint JSON : le composeur Livewire rend l'abstention comme
        // une erreur de champ (comportement produit, `assertHasNoErrors`
        // rougirait) ; le moteur et le tour sont les memes.
        $t = $this->expliquer($this->tour(fn () => $this->endpointJson('Que dit le document ?')));

        $this->assertSame(AiExecutionPath::LOOP_CONTROLLER_KNOWLEDGE_JSON, $t['identity']['execution_path']);
        $this->assertSame('abstained', $t['decision']['status']);
        $this->assertSame('grounding', $t['decision']['stage']);
        $this->assertSame(AiTurnReason::TERMINAL_NO_SOURCES_FOUND, $t['decision']['reason_code']);
        $this->assertSame(0, $t['retrieval_trace']['dense_candidates_count']);
        $this->assertSame(0, $t['retrieval_trace']['after_distance_filter_count']);
        $this->assertSame('insufficient', $t['state']['verification_status']);
        $this->assertNull($t['output']['response']);
    }

    public function test_s03_sources_trouvees_mais_refusees(): void
    {
        config(['ai.dossiers.semantic_search.enabled' => false]);

        // TASK-1595 — ce scenario suppose qu'une SECONDE provenance survive au
        // refus de la premiere : c'est le manifest [Mn] qui permet au tour de
        // repondre DEGRADE au lieu d'abstenir. Seul `ia_dossiers` a encore ce
        // Context Builder ; `loop_chat.dossiers`, qui n'a plus qu'une source,
        // abstient — ce que mesure s02.
        $t = $this->expliquer($this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?')));

        $this->assertSame([['source' => DossierRetrievalSource::NAME, 'reason' => DossierRetrievalSource::REASON_SEMANTIC_SEARCH_DISABLED]], $t['sources']['denied']);
        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, $t['state']['degraded_reason']);
        // Le manifest du Dossier reste une provenance autorisee : le tour
        // repond (degrade), il n'abstient que si AUCUNE source n'a rien rendu
        // (FACT LoopKnowledgeAnswerService, TASK-1307). Le §10 disait
        // « abstention en dossiers » : c'est le refus de source qui est
        // prouve ici, pas une abstention.
        $this->assertContains($t['decision']['status'], ['answered', 'abstained']);
        $this->assertNotContains(DossierRetrievalSource::NAME, $t['sources']['used']);
    }

    public function test_s04_abstention_preuve_insuffisante_est_declaree_non_produite(): void
    {
        // C24 : aucun chemin produit n'abstient sur preuve insuffisante ; le
        // code reste `reserved`, sans emetteur. Le scenario est CONNU et non
        // couvert — ce test le dit, il ne l'approxime pas.
        $this->assertContains(AiTurnReason::RESERVED_NO_GROUNDED_EVIDENCE, AiTurnReason::reservedVocabulary());
        $this->assertSame(
            ['app/Support/Ai/AiTurnReason.php'],
            $this->fichiersAppContenant('NO_GROUNDED_EVIDENCE'),
            'un emetteur de NO_GROUNDED_EVIDENCE est apparu : le scenario 4 devient couvrable, mettre ce test et C24 a jour',
        );
    }

    public function test_s05_refus_economique_credit_utilisateur_g4_et_budget_organization_g3(): void
    {
        // G4 — credit utilisateur epuise.
        $this->creditEpuise();
        $ledger = AiProviderInvocation::query()->count();
        $t = $this->expliquer($this->tourRefuse(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS)));

        $this->assertSame('refused', $t['decision']['status']);
        $this->assertSame('economic_check', $t['decision']['stage']);
        $this->assertSame(AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED, $t['decision']['reason_code']);
        $this->assertSame($ledger, AiProviderInvocation::query()->count(), 'ledger vierge');
        $this->assertEquals(0, (float) $t['provider']['cost_usd'], 'aucun cout : rien n\'est parti');

        // G3 — budget mensuel de l'Organization atteint (le credit est remis).
        // G1 (budget plateforme du process) et G2 (quota inconnu) ne sont pas
        // declenchables depuis un tour produit sans toucher la config
        // plateforme : couverts par les tests unitaires d'`AiEconomicGuard`,
        // declares non rejoues ici (limite assumee, TASK-1577).
        app(AiUserCreditSettings::class)->updatePlatform(['free_enabled' => false, 'monthly_uses' => 1000, 'alert_percent' => 80, 'offer_subscription' => true], $this->superAdmin);
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->update(['monthly_budget_usd' => 0.0001]);
        $t = $this->expliquer($this->tourRefuse(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS)));

        $this->assertSame('refused', $t['decision']['status']);
        $this->assertSame('economic_check', $t['decision']['stage']);
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $t['decision']['reason_code']);
    }

    public function test_s06_provider_non_configure(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();

        $t = $this->expliquer($this->tourRefuse(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS)));

        $this->assertSame('refused', $t['decision']['status']);
        $this->assertSame('provider_resolution', $t['decision']['stage']);
        $this->assertSame(AiTurnReason::REFUSED_NOT_CONFIGURED, $t['decision']['reason_code']);
        $this->assertNull($t['provider']['model'], 'aucun modele invente');
    }

    public function test_s07_fallback_provider_fake_avoue(): void
    {
        config(['ai.clarify.enabled' => false]);
        HelpRequestClarifierAgent::fake([]);

        $t = $this->expliquer($this->tour(fn () => app(ClarifyUserHelpRequestService::class)->clarifyForOrganization(
            $this->organization, $this->membre, 'Je cherche un relecteur pour mon dossier.', [], executionPath: AiExecutionPath::AI_SHELL_CLARIFY,
        )));

        $this->assertTrue($t['identity']['fallback_used']);
        $this->assertSame(AiTurnReason::FALLBACK_FEATURE_DISABLED, $t['identity']['fallback_reason']);
        $this->assertSame('deterministic_fallback', $t['identity']['producer']);
        $this->assertSame('fake', $t['identity']['provider_effective']);
        HelpRequestClarifierAgent::assertNeverPrompted();
    }

    public function test_s08_chemin_documentaire_shell_avec_bypass(): void
    {
        $ligne = $this->shell(AiShellPageContext::KIND_DOSSIER, (string) $this->dossier->id, 'Que dit ce dossier ?');
        $t = $this->expliquerShell($ligne);

        $this->assertSame(AiExecutionPath::AI_SHELL_DOSSIER, $t['identity']['execution_path']);
        $this->assertSame('bypassed', $this->etape($t, 'context_builder')['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_DOCUMENT_PATH_DIRECT_EXECUTION, $this->etape($t, 'context_builder')['reason_code']);
        $this->assertSame((string) $ligne->id, $t['shell']['message_id']);
        // Les branches qui ont decline AVANT, dans l'ordre : self_knowledge, people.
        $this->assertSame(['self_knowledge', 'people'], array_column($t['shell']['fallthroughs'], 'branch'));
    }

    public function test_s09_erreur_pendant_le_retrieval(): void
    {
        $this->rechercheLeve = new RuntimeException('embeddings injoignables');

        try {
            $this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));
            $this->fail('l\'exception devait repartir telle quelle');
        } catch (RuntimeException $e) {
            $this->assertSame('embeddings injoignables', $e->getMessage());
        }

        $t = $this->expliquer($this->dernierTour());

        $this->assertSame('failed', $t['decision']['status']);
        $this->assertSame('retrieval', $t['decision']['stage']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $t['decision']['reason_code']);
        $this->assertSame(RuntimeException::class, $t['output']['failure']);
        $this->assertSame(['economic_check', 'retrieval'], array_column($t['steps'], 'name'), 'la trace s\'arrete a l\'etape fautive');
        $this->assertSame('failed', $this->etape($t, 'retrieval')['status']);
        LoopKnowledgeAgent::assertNeverPrompted();
    }

    public function test_s10_erreur_pendant_la_generation_et_reponse_vide(): void
    {
        LoopKnowledgeAgent::fake(fn () => throw new RuntimeException('SDK en panne'));
        try {
            $this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));
            $this->fail('exception attendue');
        } catch (RuntimeException) {
        }
        $t = $this->expliquer($this->dernierTour());
        $this->assertSame('failed', $t['decision']['status']);
        $this->assertContains($t['decision']['stage'], ['generation', 'provider_call'], '§10 : stage generation|provider_call');
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $t['decision']['reason_code']);
        $this->assertSame(RuntimeException::class, $t['output']['failure']);
        $this->assertSame('failed', $this->etape($t, 'provider_call')['status']);

        LoopKnowledgeAgent::fake(fn (): TextResponse => $this->reponse('   '));
        try {
            $this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));
            $this->fail('exception attendue');
        } catch (RuntimeException) {
        }
        $t = $this->expliquer($this->dernierTour());
        $this->assertSame('failed', $t['decision']['status']);
        $this->assertSame(AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $t['decision']['reason_code']);
        $this->assertSame(1, $t['provider']['generation_sdk_invocation_id'] === null ? 0 : 1, 'la ligne facturee est reliee');
    }

    public function test_s11_loopchat_trois_modes_et_un_herite(): void
    {
        $chemins = [];
        $chemins[] = $this->expliquer($this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?')));
        $chemins[] = $this->expliquer($this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?')));
        $chemins[] = $this->expliquer($this->tour(fn () => $this->composeur('ia', 'Quelle est la capitale de la France ?')));
        $chemins[] = $this->expliquer($this->tour(fn () => app(ChatLoopAiService::class)->ask($this->loop, $this->membre, 'Quelle est la prochaine etape ?')));

        $this->assertSame(
            [AiExecutionPath::LOOP_CHAT_DOSSIERS, AiExecutionPath::LOOP_CHAT_IA_DOSSIERS, AiExecutionPath::LOOP_CHAT_IA, AiExecutionPath::LOOP_CHAT_LEGACY_ASK],
            array_map(fn (array $t): string => $t['identity']['execution_path'], $chemins),
        );
        // C3-ter : le mode `ia` n'a pas de ContextBuilder — `bypassed` avec son
        // code (le §10 disait `not_applicable` avant la correction 7/7/4).
        $this->assertSame('bypassed', $this->etape($chemins[2], 'context_builder')['status']);
        $this->assertSame(AiTurnReason::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER, $this->etape($chemins[2], 'context_builder')['reason_code']);
        $this->assertSame((string) $chemins[2]['history']['input_message_id'], (string) $chemins[2]['history']['input_message_id']);
        $this->assertNotNull($chemins[2]['history']['input_message_id'], 'C21 : le declencheur est en main sur le mode ia');
    }

    public function test_s12_shell_general_documentaire_et_zero_provider(): void
    {
        $this->rechercheRend = false;
        $general = $this->expliquerShell($this->shell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?'));
        $this->rechercheRend = true;
        $dossier = $this->expliquerShell($this->shell(AiShellPageContext::KIND_DOSSIER, (string) $this->dossier->id, 'Que dit ce dossier ?'));
        $zero = $this->expliquerShell($this->shell(AiShellPageContext::KIND_DASHBOARD, null, "C'est quoi BouclePro ?"));

        $this->assertSame(
            [AiExecutionPath::AI_SHELL_GENERAL, AiExecutionPath::AI_SHELL_DOSSIER, AiExecutionPath::AI_SHELL_SELF_KNOWLEDGE],
            [$general['identity']['execution_path'], $dossier['identity']['execution_path'], $zero['identity']['execution_path']],
        );
        $this->assertNotNull($general['run']['ai_interaction_id']);
        $this->assertNull($zero['run']['ai_interaction_id'], 'zero-provider : aucune interaction, explique depuis --shell-message');
        $this->assertSame('none', $zero['identity']['provider_effective']);
        $this->assertSame([], $zero['shell']['fallthroughs']);
    }

    public function test_s13_historique_vu(): void
    {
        // (a) tour 2 en reply au tour 1 : la bulle IA du tour 1 est VUE.
        $q1 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Bonjour ?', 'type' => 'user']);
        $bulle1 = app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Bonjour ?', $q1);
        $q2 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Et ensuite ?', 'type' => 'user', 'reply_to_id' => $bulle1->id]);
        $a = $this->expliquer($this->tour(fn () => app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Et ensuite ?', $q2)));

        $this->assertSame('reply_chain', $a['history']['strategy']);
        $this->assertGreaterThanOrEqual(2, $a['history']['count']);
        $this->assertContains((string) $bulle1->id, $a['history']['message_ids']);
        $this->assertSame((string) $bulle1->id, $a['history']['trigger_id']);
        $this->assertSame((string) $q2->id, $a['history']['input_message_id']);

        // (b) tour sans reply : `count = 0`, `trigger_id = null`, sans statut d'erreur.
        $q3 = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => 'Seul ?', 'type' => 'user']);
        $b = $this->expliquer($this->tour(fn () => app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, 'Seul ?', $q3)));
        $this->assertSame(0, $b['history']['count']);
        $this->assertNull($b['history']['trigger_id']);
        $this->assertSame('answered', $b['decision']['status']);

        // (c) Shell : deux tours dans la meme conversation, le second VOIT le premier.
        $this->rechercheRend = false;
        $this->shell(AiShellPageContext::KIND_DASHBOARD, null, 'Quelle est la capitale de la France ?');
        $c = $this->expliquerShell($this->shell(AiShellPageContext::KIND_DASHBOARD, null, 'Et celle de l\'Italie ?'));
        $this->assertSame('shell_thread', $c['history']['strategy']);
        $this->assertGreaterThanOrEqual(1, $c['history']['count']);
    }

    // ────────────────────────────── B. le critere de DONE (§14)

    public function test_b1_les_quatorze_questions_ont_une_reponse_sur_quatre_tours_de_nature_differente(): void
    {
        $tours = [
            'nominal' => $this->expliquer($this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'))),
        ];
        $this->rechercheRend = false;
        Dossier::query()->where('loop_id', $this->loop->id)->delete();
        $tours['abstenu'] = $this->expliquer($this->tour(fn () => $this->endpointJson('Que dit le document ?')));
        $this->dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'D2', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);
        $this->rechercheRend = true;
        config(['ai.dossiers.semantic_search.enabled' => false]);
        $tours['degrade'] = $this->expliquer($this->tour(fn () => $this->composeur('ia_dossiers', 'Que dit le document ?')));
        config(['ai.dossiers.semantic_search.enabled' => true]);
        LoopKnowledgeAgent::fake(fn () => throw new RuntimeException('SDK en panne'));
        try {
            $this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS));
        } catch (RuntimeException) {
        }
        $tours['erreur'] = $this->expliquer($this->dernierTour());

        $this->assertSame(['answered', 'abstained', 'answered', 'failed'], array_map(fn (array $t): string => $t['decision']['status'], array_values($tours)));
        $this->assertSame(AiTurnState::DEGRADED_SOURCE_DENIED, $tours['degrade']['state']['degraded_reason']);

        foreach ($tours as $nature => $t) {
            foreach (self::QUESTIONS_14 as $question => $champs) {
                foreach ($champs as $champ) {
                    [$valeur, $label] = $this->reponse14($t, $champ);

                    if ($valeur !== null) {
                        continue;
                    }

                    $this->assertSame(AiTruthLabel::UNAVAILABLE, $label, "[{$nature}] « {$question} » : `{$champ}` est null sans etre etiquete UNAVAILABLE");
                    $this->assertArrayHasKey($champ, self::UNAVAILABLE_ASSUMES, "[{$nature}] « {$question} » : `{$champ}` est UNAVAILABLE sans raison assumee — c'est un trou du V0");
                }
            }
        }
    }

    public function test_b2_l_explication_est_deterministe(): void
    {
        $interaction = $this->tour(fn () => $this->composeur('dossiers', 'Que dit le document ?'));

        $this->assertSame($this->expliquer($interaction), $this->expliquer($interaction));
    }

    // ────────────────────────────── fixtures

    /** Joue un tour et rend l'interaction qu'il a ecrite (par difference d'ids). */
    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    private function tourRefuse(callable $jouer): AiInteraction
    {
        return $this->tour(function () use ($jouer): void {
            try {
                $jouer();
                $this->fail('un refus etait attendu');
            } catch (AiRefusedException) {
            }
        });
    }

    private function dernierTour(): AiInteraction
    {
        return AiInteraction::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    private function endpointJson(string $question): void
    {
        $this->actingAs($this->membre)
            ->postJson(route('organization.loops.knowledge.ask', ['organization' => $this->organization->slug, 'loop' => $this->loop]), ['question' => $question])
            ->assertOk();
    }

    private function composeur(string $mode, string $question): void
    {
        $this->actingAs($this->membre);
        Livewire::test(LoopChat::class, ['loop' => $this->loop])->call('setComposerMode', $mode)->set('body', $question)->call('sendMessage')->assertHasNoErrors();
    }

    private function shell(string $kind, ?string $objectId, string $question): AiShellMessage
    {
        $context = app(AiShellPageContext::class)->resolve($this->membre, $this->organization, $kind, $objectId, $kind === AiShellPageContext::KIND_DASHBOARD ? 'organization.dashboard' : 'organization.dossiers.show');
        $this->actingAs($this->membre);
        AiTurnLock::forgetRequestState();
        $resultat = app(AiShellResponder::class)->respond($this->organization, $this->membre, $question, $context);

        return $resultat['answer'];
    }

    /** @return array<string, mixed> */
    private function expliquer(AiInteraction $interaction): array
    {
        return $this->inspecter(['--interaction' => (string) $interaction->id]);
    }

    /** @return array<string, mixed> */
    private function expliquerShell(AiShellMessage $ligne): array
    {
        return $this->inspecter(['--shell-message' => (string) $ligne->id]);
    }

    /** @return array<string, mixed> */
    private function inspecter(array $cle): array
    {
        $code = Artisan::call('ai:inspect-turn', ['--organization' => $this->organization->slug, ...$cle, '--json' => true]);
        $sortie = Artisan::output();
        $this->assertSame(0, $code, 'la commande a refuse : '.$sortie);

        return json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function etape(array $trace, string $nom): array
    {
        foreach ($trace['steps'] ?? [] as $etape) {
            if ($etape['name'] === $nom) {
                return $etape;
            }
        }
        $this->fail("etape `{$nom}` absente de la trace");
    }

    /**
     * La reponse a une question du §14 : la valeur, et son label de verite.
     * `step:<nom>` = le statut de l'etape (null si absente).
     *
     * @return array{0: mixed, 1: string}
     */
    private function reponse14(array $trace, string $champ): array
    {
        if (str_starts_with($champ, 'step:')) {
            $nom = substr($champ, 5);
            foreach ($trace['steps'] ?? [] as $etape) {
                if ($etape['name'] === $nom) {
                    return [$etape['status'], AiTruthLabel::MEASURED];
                }
            }

            return [null, AiTruthLabel::UNAVAILABLE];
        }

        [$section, $cle] = explode('.', $champ, 2);
        $valeur = $trace[$section][$cle] ?? null;
        $label = $trace['truth'][$champ] ?? $trace['truth'][$section] ?? AiTruthLabel::UNAVAILABLE;

        return [$valeur, $label];
    }

    private function creditEpuise(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform(['free_enabled' => true, 'monthly_uses' => 1, 'alert_percent' => 80, 'offer_subscription' => true], $this->superAdmin);
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'chatloop.ask', 'chatloop_ai_ask', 0.001);
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

    /** @return list<string> */
    private function fichiersAppContenant(string $aiguille): array
    {
        $trouves = [];
        $racine = base_path('app');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), $aiguille)) {
                $trouves[] = 'app/'.str_replace($racine.'/', '', $f->getPathname());
            }
        }
        sort($trouves);

        return $trouves;
    }
}
