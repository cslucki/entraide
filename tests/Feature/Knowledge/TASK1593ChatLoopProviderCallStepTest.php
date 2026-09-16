<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1593 — un tour `loop_chat.ia` REUSSI dit que le fournisseur a ete
 * appele : etape `provider_call = executed`, au meme point logique que le
 * writer Dossiers (LAB.MODE_CHANGE_1, matrice CORE du 17/09 : le ledger
 * facturait l'appel, la trace n'en disait rien).
 *
 * Observabilite pure : aucun comportement IA, aucune economie, aucun schema.
 *
 *   A. succes ia -> `provider_call executed` (et pas de `generation` : parite)
 *   B. echec provider -> `provider_call failed` inchange, jamais `executed`
 *   C. dossiers / ia_dossiers : liste d'etapes STRICTEMENT inchangee
 *   D. l'Inspector lit l'etape
 */
#[Group('ai')]
class TASK1593ChatLoopProviderCallStepTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $admin;

    private Loop $loop;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        DossierRetrievalTraceRecorder::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1593', 'loops_enabled' => true, 'members_can_create_loops' => true, 'ai_profiles_enabled' => true]);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        OrganizationAiSetting::factory()->create(['organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1593']);
        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle 1593');
        $this->dossier = Dossier::factory()->create(['organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'Dossier 1593', 'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key', 'ai.default_for_embeddings' => 'openrouter',
            'ai_pricing.overrides' => [], 'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id], 'ai.knowledge.retrieval_trace.enabled' => true,
            'ai.chatloop.enabled' => true, 'ai.chatloop.min_summary_words' => 0,
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $this->dossier->id, 'dossier_name' => $this->dossier->name, 'source_type' => 'file',
            'blog_post_id' => null, 'title' => null, 'slug' => null, 'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chunk_index' => 0, 'content' => 'Contenu du document.', 'distance' => 0.2,
        ]])->byDefault();

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

    // ────────────────────────────── A. succes

    public function test_a1_un_tour_ia_reussi_emet_provider_call_executed(): void
    {
        $t = $this->inspecter($this->tourIa('Bonjour ?'));

        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA, $t['identity']['execution_path']);
        $this->assertSame('answered', $t['decision']['status']);
        $this->assertSame('executed', $this->etape($t, 'provider_call')['status']);
        $this->assertArrayNotHasKey('reason_code', array_filter($this->etape($t, 'provider_call'), static fn ($v): bool => $v !== null));
        // Parite writer Dossiers : `generation` n'est emise sur succes par aucun writer P0 (dette OBSERVABILITY documentee).
        $this->assertNull($this->etapeOuNull($t, 'generation'));
        // L'etape suit economic_check et l'appel n'est compte qu'une fois.
        $noms = array_column($t['steps'], 'name');
        $this->assertSame(1, count(array_keys($noms, 'provider_call', true)));
        $this->assertGreaterThan(array_search('economic_check', $noms, true), array_search('provider_call', $noms, true));
    }

    public function test_a2_les_etapes_attendues_par_lab_mode_change_1_sont_toutes_la(): void
    {
        // Ce que le scenario CORE declare pour son tour 2 (`loop_chat.ia`).
        $t = $this->inspecter($this->tourIa('Et le budget ?'));

        $attendu = ['conversation_history' => 'executed', 'economic_check' => 'executed', 'context_builder' => 'bypassed', 'provider_call' => 'executed'];
        foreach ($attendu as $nom => $statut) {
            $this->assertSame($statut, $this->etape($t, $nom)['status'], $nom);
        }
        $this->assertSame(AiTurnReason::LLM_PATH_NO_CONTEXT_BUILDER, $this->etape($t, 'context_builder')['reason_code']);
    }

    // ────────────────────────────── B. echec

    public function test_b1_un_provider_qui_tombe_garde_provider_call_failed_et_jamais_executed(): void
    {
        LoopDirectAnswerAgent::fake(function (): TextResponse {
            throw new \RuntimeException('provider down');
        });

        $t = $this->inspecter($this->tourIa('Bonjour ?', refusAttendu: true));

        $this->assertSame('failed', $t['decision']['status']);
        $etapes = array_values(array_filter($t['steps'], static fn (array $s): bool => $s['name'] === 'provider_call'));
        $this->assertCount(1, $etapes, 'une seule etape provider_call');
        $this->assertSame('failed', $etapes[0]['status']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $etapes[0]['reason_code']);
    }

    // ────────────────────────────── C. les autres writers ne bougent pas

    public function test_c1_dossiers_et_ia_dossiers_gardent_exactement_leurs_etapes(): void
    {
        $dossiers = $this->inspecter($this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS)));
        $this->assertSame(['economic_check', 'retrieval', 'rerank', 'context_builder', 'conversation_history', 'provider_call', 'grounding'], array_column($dossiers['steps'], 'name'));
        $this->assertSame('executed', $this->etape($dossiers, 'provider_call')['status']);

        $hybride = $this->inspecter($this->tour(fn () => app(LoopKnowledgeAnswerService::class)->answerHybrid($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_IA_DOSSIERS)));
        $this->assertSame(AiExecutionPath::LOOP_CHAT_IA_DOSSIERS, $hybride['identity']['execution_path']);
        $this->assertSame(1, count(array_keys(array_column($hybride['steps'], 'name'), 'provider_call', true)));
        $this->assertSame('executed', $this->etape($hybride, 'provider_call')['status']);
    }

    // ────────────────────────────── D. Inspector

    public function test_d1_l_inspector_montre_l_etape_du_tour_ia(): void
    {
        $interaction = $this->tourIa('Bonjour ?');

        $this->actingAs($this->admin)->get(route('admin.ai-turns.show', ['interaction' => (string) $interaction->id]))
            ->assertOk()
            ->assertSee('provider_call')
            ->assertSee('loop_chat.ia');
    }

    // ────────────────────────────── fixtures

    private function tourIa(string $question, bool $refusAttendu = false): AiInteraction
    {
        $trigger = LoopMessage::create(['loop_id' => $this->loop->id, 'organization_id' => $this->organization->id, 'sender_id' => $this->membre->id, 'body' => $question, 'type' => 'user']);

        return $this->tour(function () use ($question, $trigger, $refusAttendu): void {
            try {
                app(ChatLoopAiService::class)->respondInThread($this->loop, $this->membre, $question, $trigger, publish: false);
                $this->assertFalse($refusAttendu, 'un refus etait attendu');
            } catch (\RuntimeException $e) {
                $this->assertTrue($refusAttendu, 'refus inattendu : '.$e->getMessage());
            }
        });
    }

    private function tour(callable $jouer): AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();
        $jouer();

        return AiInteraction::query()->whereNotIn('id', $deja)->sole();
    }

    /** @return array<string, mixed> */
    private function inspecter(AiInteraction $interaction): array
    {
        return AiTurnInspection::fromPersistedTurn($interaction->refresh());
    }

    /** @return array<string, mixed> */
    private function etape(array $trace, string $nom): array
    {
        $etape = $this->etapeOuNull($trace, $nom);
        $this->assertNotNull($etape, "etape {$nom} absente : ".json_encode(array_column($trace['steps'] ?? [], 'name')));

        return $etape;
    }

    /** @return array<string, mixed>|null */
    private function etapeOuNull(array $trace, string $nom): ?array
    {
        foreach ($trace['steps'] ?? [] as $etape) {
            if ($etape['name'] === $nom) {
                return $etape;
            }
        }

        return null;
    }

    private function reponse(string $texte): TextResponse
    {
        return new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'));
    }
}
