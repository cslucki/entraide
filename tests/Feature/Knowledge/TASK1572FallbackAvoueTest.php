<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationConsole;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiConsumption;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Ai\RecordsAiConsumption;
use Tests\TestCase;

/**
 * TASK-1572 / CDC-01 V0-D — le fallback est avoue.
 *
 *  A. les QUATRE sorties du clarifier vers `FakeAIProvider` laissent un tour qui
 *     le dit : `fallback_used = true`, `fallback_reason` = la sortie prise,
 *     `producer = deterministic_fallback`, `provider_effective = fake`, etape
 *     `generation: fallback` — et la reponse rendue au membre est LA MEME ;
 *  B. le contrat de la ligne : non generative (rien n'est parti), statut de
 *     ligne `fallback`, exclue des lecteurs economiques, A7 sur la console ;
 *  C. le nominal n'est JAMAIS confondu : `fallback_used = false` MESURE,
 *     `provider_effective` reel, `producer` du chemin — sur le clarifier comme
 *     sur les autres writers P0.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1572FallbackAvoueTest extends TestCase
{
    use RecordsAiConsumption;
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    /** Lignes ledger posees par le harnais AVANT le tour sous test. */
    private int $ledgerAvant = 0;

    protected function setUp(): void
    {
        parent::setUp();

        AiTurnTrace::forgetJournal();

        $this->organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr', 'slug' => 'org-1572']);
        app()->instance('current_organization', $this->organization);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-1572',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter', 'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [], 'ai.clarify.enabled' => true,
        ]);

        $this->faireRepondreLeClarifier();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AiTurnTrace::forgetJournal();

        parent::tearDown();
    }

    // ────────────────────────────── A. les quatre sorties

    public function test_a1_feature_coupee_le_repli_est_avoue_et_la_reponse_est_la_meme(): void
    {
        config(['ai.clarify.enabled' => false]);
        HelpRequestClarifierAgent::fake([]);

        $resultat = $this->clarifier('Je cherche un relecteur pour mon dossier.');

        $this->assertSame('deterministic_fallback', $resultat->producer);
        HelpRequestClarifierAgent::assertNeverPrompted();

        $tour = $this->tourDeRepli(AiTurnReason::FALLBACK_FEATURE_DISABLED);
        // Aucun provider resolu : rien d'invente.
        $this->assertSame('', $tour->model);
        $this->assertArrayNotHasKey('provider', $tour->metadata);
        // L'identite du chemin est la, meme sur cette sortie tres precoce.
        $this->assertSame(AiExecutionPath::AI_SHELL_CLARIFY, $tour->metadata['turn']['identity']['execution_path']);
    }

    public function test_a2_provider_non_configure_le_repli_est_avoue(): void
    {
        OrganizationAiSetting::query()->where('organization_id', $this->organization->id)->delete();
        HelpRequestClarifierAgent::fake([]);

        $resultat = $this->clarifier('Je cherche un relecteur.');

        $this->assertSame('deterministic_fallback', $resultat->producer);
        $tour = $this->tourDeRepli(AiRefusedException::CODE_NOT_CONFIGURED);
        $this->assertSame('', $tour->model);
        // Le ContextBuilder a tourne avant : l'etape est la, dans l'ordre.
        $this->assertSame(['context_builder', 'conversation_history', 'generation'], array_column($tour->metadata['turn']['steps'], 'name'));
    }

    public function test_a3_refus_economique_le_repli_est_avoue_avec_le_code_du_garde(): void
    {
        $this->creditEpuise();
        HelpRequestClarifierAgent::fake([]);

        $resultat = $this->clarifier('Je cherche un relecteur.');

        $this->assertSame('deterministic_fallback', $resultat->producer);
        $tour = $this->tourDeRepli(AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED);
        // Le modele ETAIT resolu : il est ecrit tel quel.
        $this->assertSame('openrouter/openai/gpt-4o-mini', $tour->model);
        $etapes = array_column($tour->metadata['turn']['steps'], 'status', 'name');
        $this->assertSame('denied', $etapes['economic_check']);
        $this->assertSame('fallback', $etapes['generation']);
    }

    public function test_a4_provider_qui_leve_la_ligne_failed_avoue_le_repli(): void
    {
        HelpRequestClarifierAgent::fake(function (): never {
            throw new RuntimeException('provider down');
        });

        $resultat = $this->clarifier('Je cherche un relecteur.');

        $this->assertSame('deterministic_fallback', $resultat->producer);

        $tour = AiInteraction::query()->sole();
        $this->assertSame('failed', $tour->metadata['status'], 'la tentative IA reste une ligne failed, facturee au ledger');
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $turn = $tour->metadata['turn'];
        $this->assertSame(AiTurnState::TURN_FAILED, $turn['status']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turn['reason_code']);
        $this->assertTrue($turn['identity']['fallback_used']);
        $this->assertSame(AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turn['identity']['fallback_reason']);
        $this->assertSame('deterministic_fallback', $turn['identity']['producer']);
        $this->assertSame('fake', $turn['identity']['provider_effective']);
    }

    // ────────────────────────────── B. contrat de la ligne, lecteurs

    public function test_b1_la_ligne_de_repli_ne_coute_rien_et_les_lecteurs_le_savent(): void
    {
        config(['ai.clarify.enabled' => false]);
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'help_request.clarify', 'clarify_help_request', 0.001);
        $fenetre = new AiConsumptionFilters(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->addDay());
        $conso = app(OrganizationAiConsumption::class);
        $avant = $conso->summary((string) $this->organization->id, $fenetre);
        $ledger = AiProviderInvocation::query()->count();

        $this->clarifier('Je cherche un relecteur.');

        $this->assertSame($ledger, AiProviderInvocation::query()->count(), 'ledger vierge (I3)');
        $this->assertSame($avant['trace_count'], $conso->summary((string) $this->organization->id, $fenetre)['trace_count'], 'le repli n\'est pas une consommation');
        $this->assertContains(AiTurnState::LINE_FALLBACK, AiTurnState::NON_GENERATIVE_STATUSES);

        $activite = app(AiProviderInvocationConsole::class)->recentActivityForUser((string) $this->organization->id, (string) $this->membre->id);
        $repli = array_values(array_filter($activite, static fn (array $r): bool => $r['status'] === AiTurnState::LINE_FALLBACK));
        $this->assertCount(1, $repli);
        $this->assertSame('turn', $repli[0]['kind']);
        $this->assertSame('not_applicable', $repli[0]['cost_state']);

        $this->actingAs($this->membre)->get(route('profile.ai-usage'))->assertOk()->assertSee(__('ai.usage_status_fallback'));
    }

    // ────────────────────────────── C. le nominal n'est jamais confondu

    public function test_c1_le_nominal_porte_fallback_used_false_mesure_et_son_producer(): void
    {
        $resultat = $this->clarifier('Je cherche un relecteur.');

        $this->assertSame('laravel_ai_sdk', $resultat->producer);

        $tour = AiInteraction::query()->sole();
        $identity = $tour->metadata['turn']['identity'];
        $this->assertFalse($identity['fallback_used']);
        $this->assertArrayNotHasKey('fallback_reason', $identity);
        $this->assertSame('laravel_ai_sdk', $identity['producer'], 'C17 : le producer du produit, non renomme');
        $this->assertSame('openrouter', $identity['provider_effective']);
        $this->assertSame('openrouter/openai/gpt-4o-mini', $identity['model']);
        // `provider_requested` : aucune source honnete, donc absent.
        $this->assertArrayNotHasKey('provider_requested', $identity);
        $this->assertSame(AiTurnState::TURN_ANSWERED, $tour->metadata['turn']['status']);
    }

    /**
     * Sabotage : ecrire `fallback_used => false` dans `identiteDuRepli()` →
     * a1..a4 et ce test rougissent : c'est LA confusion que I6 interdit.
     */
    public function test_c2_un_repli_et_un_nominal_ne_se_lisent_jamais_pareil(): void
    {
        $nominal = $this->clarifier('Question un.');
        $this->assertSame('laravel_ai_sdk', $nominal->producer);

        config(['ai.clarify.enabled' => false]);
        $repli = $this->clarifier('Question deux.');
        $this->assertSame('deterministic_fallback', $repli->producer);

        $tours = AiInteraction::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $tours);

        $lu = $tours->map(static fn (AiInteraction $t): array => [
            $t->metadata['turn']['identity']['fallback_used'],
            $t->metadata['turn']['identity']['producer'],
            $t->metadata['turn']['identity']['provider_effective'],
        ])->all();

        $this->assertSame([[false, 'laravel_ai_sdk', 'openrouter'], [true, 'deterministic_fallback', 'fake']], $lu);
    }

    // ────────────────────────────── harnais

    private function clarifier(string $phrase)
    {
        return app(ClarifyUserHelpRequestService::class)->clarifyForOrganization(
            $this->organization, $this->membre, $phrase, [], executionPath: AiExecutionPath::AI_SHELL_CLARIFY,
        );
    }

    private function tourDeRepli(string $reason): AiInteraction
    {
        $tour = AiInteraction::query()->whereNull('response')->where('input_tokens', 0)->latest('id')->firstOrFail();

        $this->assertSame(AiTurnState::LINE_FALLBACK, $tour->metadata['status']);
        $this->assertSame(0.0, (float) $tour->cost_usd);
        $this->assertFalse($tour->cost_unknown);
        $this->assertSame($this->ledgerAvant, AiProviderInvocation::query()->count(), 'ledger vierge (I3)');

        $turn = $tour->metadata['turn'];
        $this->assertSame(AiTurnState::TURN_ANSWERED, $turn['status'], 'le membre a bien une reponse');
        $this->assertTrue($turn['identity']['fallback_used']);
        $this->assertSame($reason, $turn['identity']['fallback_reason']);
        $this->assertSame('deterministic_fallback', $turn['identity']['producer']);
        $this->assertSame('fake', $turn['identity']['provider_effective']);
        $this->assertTrue(AiTurnReason::isKnown($reason));

        $generation = array_values(array_filter($turn['steps'], static fn (array $e): bool => $e['name'] === 'generation'));
        $this->assertCount(1, $generation);
        $this->assertSame('fallback', $generation[0]['status']);
        $this->assertSame(AiTurnReason::FALLBACK_FAKE_PROVIDER, $generation[0]['reason_code']);

        return $tour;
    }

    private function creditEpuise(): void
    {
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 1, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->superAdmin);
        $this->recordAiGeneration((string) $this->organization->id, (string) $this->membre->id, 'chatloop.ask', 'chatloop_ai_ask', 0.001);
        // La generation du harnais n'est pas le tour sous test.
        AiInteraction::query()->update(['response' => 'harnais']);
        $this->ledgerAvant = AiProviderInvocation::query()->count();
    }

    private function faireRepondreLeClarifier(): void
    {
        $structure = [
            'interaction_fit' => true, 'direct_reply' => '', 'title' => 'Relecture', 'clarified_request' => 'Je cherche un relecteur.',
            'help_type' => 'information', 'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structure, json_encode($structure, JSON_UNESCAPED_UNICODE), new Usage(120, 80), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }
}
