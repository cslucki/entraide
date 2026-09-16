<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopKnowledgeAnswerService;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * TASK-1571 / CDC-01 V0-C — les raisons sont des codes.
 *
 *  A. le REGISTRE est ferme et coherent : familles completes, collisions
 *     connues et seulement elles, codes reserves sans emetteur avant leur lot ;
 *  B. garde STATIQUE : dans `app/`, tout `AiTurnTrace::step()` dont le statut
 *     est bloquant passe un `reason_code` non nul ;
 *  C. garde RUNTIME : sur de vrais tours, tout verdict non-`answered` et toute
 *     etape bloquante portent un code `isKnown()` ; le provider qui leve porte
 *     `PROVIDER_CALL_FAILED` (le trou que V0-C ferme) ;
 *  D. la collision garde / exception est tranchee : `economic_check` parle la
 *     langue du GARDE.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1571ReasonRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** Les statuts d'etape qui EXIGENT un code (P0.4). */
    private const BLOCKING = ['denied', 'bypassed', 'failed', 'abstained', 'fallback'];

    private Organization $organization;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();
        AiTurnLock::forgetRequestState();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1571']);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1571',
        ]);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Boucle des codes');
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id, 'owner_id' => $this->membre->id, 'name' => 'Dossier',
            'visibility' => Dossier::VISIBILITY_LOOP, 'shared_with_loop_id' => $this->loop->id,
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openrouter', 'ai_pricing.overrides' => [],
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [(string) $this->organization->id],
        ]);

        $recherche = $this->mock(DossierSemanticSearchService::class);
        $recherche->shouldReceive('representativeChunksAcrossDossiers')->andReturn([])->byDefault();
        $recherche->shouldReceive('searchAcrossDossiers')->andReturn([[
            'chunk_id' => (string) Str::uuid(), 'dossier_id' => (string) $dossier->id, 'dossier_name' => $dossier->name,
            'source_type' => 'file', 'blog_post_id' => null, 'title' => null, 'slug' => null,
            'dossier_file_id' => (string) Str::uuid(), 'filename' => 'note.docx', 'mime_type' => 'application/pdf',
            'chunk_index' => 0, 'content' => 'Contenu.', 'distance' => 0.2,
        ]])->byDefault();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. le registre

    public function test_a1_le_registre_est_ferme_et_ses_familles_sont_completes(): void
    {
        $familles = AiTurnReason::byFamily();

        $this->assertSame(
            ['economic', 'refused', 'source', 'rerank', 'degraded', 'context_builder', 'fallthrough', 'terminal', 'reserved'],
            array_keys($familles),
        );

        foreach ($familles as $nom => $codes) {
            $this->assertNotEmpty($codes, "famille `{$nom}` vide");
            foreach ($codes as $code) {
                $this->assertTrue(AiTurnReason::isKnown($code));
                $this->assertMatchesRegularExpression('/^[a-z_]+$|^[A-Z_]+$/', $code, "`{$code}` : un identifiant technique borne, pas une phrase");
            }
        }

        $this->assertContains(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $familles['terminal']);
        $this->assertSame(['NO_GROUNDED_EVIDENCE', 'FAKE_PROVIDER_FALLBACK', 'FEATURE_DISABLED'], $familles['reserved']);
        // Decision V0-C : pas de doublon de la famille rerank.
        $this->assertFalse(AiTurnReason::isKnown('RERANK_NOT_CONFIGURED'));
        $this->assertFalse(AiTurnReason::isKnown('un_code_inconnu'));
    }

    public function test_a2_les_seules_collisions_entre_familles_sont_celles_documentees(): void
    {
        $vu = [];
        foreach (AiTurnReason::byFamily() as $famille => $codes) {
            foreach ($codes as $code) {
                $vu[$code][] = $famille;
            }
        }

        $collisions = array_filter($vu, static fn (array $f): bool => count($f) > 1);
        ksort($collisions);

        $this->assertSame([
            'EMPTY_MODEL_ANSWER' => ['fallthrough', 'terminal'],
            'NO_SOURCES_FOUND' => ['fallthrough', 'terminal'],
            'provider_unavailable' => ['rerank', 'degraded'],
        ], $collisions, 'une collision nouvelle doit etre documentee dans le registre avant d\'exister');
    }

    // ────────────────────────────── B. garde statique

    public function test_b1_aucun_step_bloquant_de_app_n_est_emis_sans_code(): void
    {
        $fautifs = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $fichier) {
            $source = $fichier->getContents();
            if ($fichier->getFilename() === 'AiTurnTrace.php' || ! str_contains($source, 'AiTurnTrace::step(')) {
                continue;
            }

            // Chaque appel `step(` jusqu'a sa parenthese fermante, multi-ligne.
            preg_match_all('/AiTurnTrace::step\((.*?)\);/s', $source, $appels);

            foreach ($appels[1] as $args) {
                $parties = array_map('trim', $this->arguments($args));
                $statut = trim($parties[3] ?? '', "'\"");

                if (! in_array($statut, self::BLOCKING, true)) {
                    continue;
                }

                $code = $parties[4] ?? 'null';
                if ($code === 'null' || $code === '') {
                    $fautifs[] = $fichier->getRelativePathname().' : '.preg_replace('/\s+/', ' ', $args);
                }
            }
        }

        $this->assertSame([], $fautifs, "des etapes bloquantes sans reason_code :\n".implode("\n", $fautifs));
    }

    public function test_b2_aucun_code_reserve_n_est_emis_avant_son_lot(): void
    {
        $emis = [];
        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $fichier) {
            if ($fichier->getFilename() === 'AiTurnReason.php') {
                continue;
            }
            if (str_contains($fichier->getContents(), 'AiTurnReason::RESERVED_')) {
                $emis[] = $fichier->getRelativePathname();
            }
        }

        $this->assertSame([], $emis);
    }

    // ────────────────────────────── C. garde runtime

    public function test_c1_le_provider_qui_leve_porte_provider_call_failed_sur_l_etape_et_le_verdict(): void
    {
        LoopKnowledgeAgent::fake(function (): never {
            throw new RuntimeException('provider down');
        });

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
            $this->fail('une erreur etait attendue');
        } catch (RuntimeException $e) {
            $this->assertSame(__('loops.ai_error'), $e->getMessage());
        }

        $tour = AiInteraction::query()->sole();
        $turn = $tour->metadata['turn'];

        $this->assertSame(AiTurnState::TURN_FAILED, $turn['status']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turn['reason_code']);
        // Le diagnostic humain reste a sa place : la classe, dans `failure`.
        $this->assertSame(RuntimeException::class, $tour->metadata['failure']);

        $etape = $this->etape($turn, 'provider_call');
        $this->assertSame('failed', $etape['status']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $etape['reason_code']);
    }

    public function test_c2_sur_de_vrais_tours_aucun_statut_bloquant_n_est_sans_code_connu(): void
    {
        // Trois issues : provider qui leve, refus economique, reponse vide.
        $tours = [];

        LoopKnowledgeAgent::fake(fn (): never => throw new RuntimeException('down'));
        $tours[] = $this->jouer();

        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse('  ', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')));
        $tours[] = $this->jouer();

        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();
        $tours[] = $this->jouer();

        $this->assertCount(3, array_filter($tours));

        foreach (AiInteraction::query()->get() as $tour) {
            $turn = $tour->metadata['turn'];
            $this->assertNotSame(AiTurnState::TURN_ANSWERED, $turn['status']);
            $this->assertTrue(AiTurnReason::isKnown($turn['reason_code'] ?? null), "verdict `{$turn['status']}` sans code connu");
            $this->assertNotEmpty($turn['stage'] ?? null);

            foreach ($turn['steps'] ?? [] as $etape) {
                if (in_array($etape['status'], self::BLOCKING, true)) {
                    $this->assertTrue(AiTurnReason::isKnown($etape['reason_code'] ?? null), "etape `{$etape['name']}` {$etape['status']} sans code connu");
                }
            }
        }
    }

    // ────────────────────────────── D. la collision tranchee

    public function test_d1_economic_check_parle_la_langue_du_garde_et_la_resolution_celle_de_l_exception(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->update(['monthly_budget_usd' => 0.0001]);
        AiInteraction::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->organization->id, 'process' => 'loop_knowledge.answer',
            'feature' => 'loop_knowledge_answer', 'model' => 'x', 'prompt' => 'p', 'response' => 'r',
            'input_tokens' => 10, 'output_tokens' => 10, 'cost_usd' => 1.0, 'cost_unknown' => false, 'metadata' => ['status' => 'success'],
        ]);

        $deja = AiInteraction::query()->pluck('id')->all();
        $this->jouer();
        $refus = AiInteraction::query()->whereNotIn('id', $deja)->sole();

        // Famille 1 (GARDE), pas famille 2 (exception `organization_budget_reached`).
        $this->assertSame(AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED, $refus->metadata['turn']['reason_code']);
        $this->assertNotSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $refus->metadata['turn']['reason_code']);
        $this->assertContains($refus->metadata['turn']['reason_code'], AiTurnReason::byFamily()['economic']);
    }

    // ────────────────────────────── harnais

    private function jouer(): ?AiInteraction
    {
        $deja = AiInteraction::query()->pluck('id')->all();
        AiTurnLock::forgetRequestState();

        try {
            app(LoopKnowledgeAnswerService::class)->answer($this->loop, $this->membre, 'Que dit le document ?', null, publish: false, executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS);
        } catch (RuntimeException) {
            // refus ou echec : c'est le tour ecrit qui nous interesse
        }

        return AiInteraction::query()->whereNotIn('id', $deja)->first();
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

    /**
     * Decoupe une liste d'arguments PHP sur les virgules de premier niveau
     * (les crochets des metriques et les parentheses d'appels ne comptent pas).
     *
     * @return list<string>
     */
    private function arguments(string $args): array
    {
        $parties = [];
        $courant = '';
        $profondeur = 0;

        foreach (str_split($args) as $c) {
            if ($c === '[' || $c === '(') {
                $profondeur++;
            } elseif ($c === ']' || $c === ')') {
                $profondeur--;
            }

            if ($c === ',' && $profondeur === 0) {
                $parties[] = $courant;
                $courant = '';

                continue;
            }

            $courant .= $c;
        }

        if (trim($courant) !== '') {
            $parties[] = $courant;
        }

        return $parties;
    }
}
