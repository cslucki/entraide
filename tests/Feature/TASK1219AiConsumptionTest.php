<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiConsumption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Metrologie et console « Consommation IA » d'une Organization (TASK-1219).
 *
 * Ce que ces tests protegent, dans l'ordre :
 *
 * 1. L'ISOLATION TENANT : aucune trace d'une autre Organization, et aucune
 *    trace orpheline (`organization_id` NULL) ne doit entrer dans un compteur.
 * 2. LES TROIS ETATS DE COUT (TASK-1132) : `false` (connu, 0 legitime inclus),
 *    `true` (non mesurable) et `null` (jamais evalue) ne se fondent jamais l'un
 *    dans l'autre — c'est l'invariant que toute la TASK sert.
 * 3. L'ABSENCE D'INVENTION : un provider absent reste « — », il n'est jamais
 *    deduit du modele ; une somme sans ligne mesuree vaut `null`, pas `0.0`.
 */
class TASK1219AiConsumptionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Tenant
    // ------------------------------------------------------------------

    public function test_counters_are_strictly_scoped_to_the_organization(): void
    {
        [$orgA, $userA] = $this->organizationWithAdmin();
        [$orgB, $userB] = $this->organizationWithAdmin();

        $this->trace($orgA, $userA, cost: 1.5);
        $this->trace($orgB, $userB, cost: 99.0);
        $this->trace($orgB, $userB, cost: 99.0);

        $summary = $this->consumption()->summary($orgA->id, $this->thisMonth());

        $this->assertSame(1, $summary['trace_count']);
        $this->assertSame(1.5, $summary['known_cost_usd'], 'le cout de orgB ne doit jamais entrer dans le total de orgA');
    }

    public function test_traces_without_organization_are_never_attributed_to_anyone(): void
    {
        [$orgA, $userA] = $this->organizationWithAdmin();

        $this->trace($orgA, $userA, cost: 2.0);

        // Trace orpheline : `ai_interactions.organization_id` est nullable.
        // Elle n'est rattachable a AUCUNE Organization, donc a aucun compteur.
        $orphan = $this->trace($orgA, $userA, cost: 40.0);
        $orphan->forceFill(['organization_id' => null])->saveQuietly();

        $summary = $this->consumption()->summary($orgA->id, $this->thisMonth());

        $this->assertSame(1, $summary['trace_count']);
        $this->assertSame(2.0, $summary['known_cost_usd']);
    }

    // ------------------------------------------------------------------
    // Les trois etats de cout ne fusionnent jamais
    // ------------------------------------------------------------------

    public function test_the_three_cost_states_are_counted_separately(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 0.25);                       // connu
        $this->trace($org, $user, cost: 0.75);                       // connu
        $this->trace($org, $user, cost: null, unknown: true);        // non mesurable
        $this->trace($org, $user, cost: null, unknown: null);        // jamais evalue

        $summary = $this->consumption()->summary($org->id, $this->thisMonth());

        $this->assertSame(1.0, $summary['known_cost_usd']);
        $this->assertSame(2, $summary['measured_count']);
        $this->assertSame(1, $summary['unknown_count']);
        $this->assertSame(1, $summary['unevaluated_count']);
        $this->assertSame(4, $summary['trace_count']);
    }

    public function test_an_unknown_cost_never_contributes_zero_to_the_sum(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 3.0);
        $this->trace($org, $user, cost: null, unknown: true);

        $summary = $this->consumption()->summary($org->id, $this->thisMonth());

        // Un COALESCE(cost_usd, 0) donnerait exactement le meme 3.0 ici, mais
        // ferait passer la ligne inconnue pour mesuree : c'est `measured_count`
        // qui prouve qu'elle ne l'est pas.
        $this->assertSame(3.0, $summary['known_cost_usd']);
        $this->assertSame(1, $summary['measured_count']);
        $this->assertSame(1, $summary['unknown_count']);
    }

    public function test_a_legitimate_zero_cost_is_a_measure_not_an_unknown(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        // Reponse sans appel LLM : le cout est REELLEMENT nul et CONNU.
        $this->trace($org, $user, cost: 0.0, unknown: false);

        $summary = $this->consumption()->summary($org->id, $this->thisMonth());

        $this->assertSame(0.0, $summary['known_cost_usd'], 'un 0 legitime est une mesure, il doit rester un 0 affichable');
        $this->assertNotNull($summary['known_cost_usd']);
        $this->assertSame(1, $summary['measured_count']);
        $this->assertSame(0, $summary['unknown_count']);
    }

    public function test_a_sum_without_any_measured_row_is_null_not_zero(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: null, unknown: true);
        $this->trace($org, $user, cost: null, unknown: null);

        $summary = $this->consumption()->summary($org->id, $this->thisMonth());

        // « 0,00 $ » se lirait « ca n'a rien coute ». La verite est « rien n'est
        // mesurable » : la somme doit rester nulle au sens de l'absence.
        $this->assertNull($summary['known_cost_usd']);
        $this->assertSame(0, $summary['measured_count']);
        $this->assertSame(2, $summary['trace_count']);
    }

    public function test_an_organization_without_any_trace_reports_absence_not_zero(): void
    {
        [$org] = $this->organizationWithAdmin();

        $summary = $this->consumption()->summary($org->id, $this->thisMonth());

        $this->assertNull($summary['known_cost_usd']);
        $this->assertSame(0, $summary['trace_count']);
        $this->assertNull($summary['last_trace_at']);
    }

    // ------------------------------------------------------------------
    // Periode
    // ------------------------------------------------------------------

    public function test_the_period_window_is_half_open_like_the_economic_guard(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $from = CarbonImmutable::parse('2026-03-01 00:00:00');
        $to = $from->addMonth();

        $this->traceAt($org, $user, $from, cost: 1.0);                          // borne basse INCLUSE
        $this->traceAt($org, $user, $to->subSecond(), cost: 2.0);               // derniere seconde INCLUSE
        $this->traceAt($org, $user, $to, cost: 100.0);                          // borne haute EXCLUE
        $this->traceAt($org, $user, $from->subSecond(), cost: 100.0);           // avant la fenetre

        $summary = $this->consumption()->summary($org->id, new AiConsumptionFilters($from, $to));

        $this->assertSame(2, $summary['trace_count']);
        $this->assertSame(3.0, $summary['known_cost_usd']);
    }

    // ------------------------------------------------------------------
    // Provider : lu, jamais deduit
    // ------------------------------------------------------------------

    public function test_provider_is_read_from_metadata_never_guessed_from_the_model(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 1.0, model: 'deepseek-chat', provider: 'openrouter');
        $this->trace($org, $user, cost: 2.0, model: 'deepseek-chat', provider: 'ollama');

        $rows = $this->consumption()->byProvider($org->id, $this->thisMonth());
        $keys = array_column($rows, 'key');

        sort($keys);

        // Le meme modele servi par deux providers : le deduire du modele
        // fusionnerait ces deux lignes en une valeur inventee.
        $this->assertSame(['ollama', 'openrouter'], $keys);
    }

    public function test_a_missing_provider_stays_unknown_and_is_never_offered_as_a_filter(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 1.0, provider: null);
        $this->trace($org, $user, cost: 2.0, provider: 'openai');

        $rows = $this->consumption()->byProvider($org->id, $this->thisMonth());
        $keys = array_column($rows, 'key');

        $this->assertContains(null, $keys, 'une trace sans provider doit remonter en « — », pas disparaitre');
        $this->assertContains('openai', $keys);

        $available = $this->consumption()->availableFilters($org->id, $this->thisMonth());
        $this->assertSame(['openai'], $available['providers'], 'on ne propose pas de filtrer sur l\'inconnu');
    }

    // ------------------------------------------------------------------
    // Attribution utilisateur : prouvee par la colonne
    // ------------------------------------------------------------------

    public function test_consumption_is_attributed_to_the_user_column_without_heuristics(): void
    {
        [$org, $admin] = $this->organizationWithAdmin();
        $other = User::factory()->create(['organization_id' => $org->id]);

        $this->trace($org, $admin, cost: 1.0);
        $this->trace($org, $other, cost: 2.0);
        $this->trace($org, $other, cost: 3.0);

        $rows = collect($this->consumption()->byUser($org->id, $this->thisMonth()))->keyBy('user_id');

        $this->assertSame(1.0, $rows[$admin->id]['known_cost_usd']);
        $this->assertSame(1, $rows[$admin->id]['trace_count']);
        $this->assertSame(5.0, $rows[$other->id]['known_cost_usd']);
        $this->assertSame(2, $rows[$other->id]['trace_count']);
        $this->assertSame($other->name, $rows[$other->id]['name']);
    }

    // ------------------------------------------------------------------
    // Filtres
    // ------------------------------------------------------------------

    public function test_each_dimension_filter_narrows_the_scope(): void
    {
        [$org, $admin] = $this->organizationWithAdmin();
        $other = User::factory()->create(['organization_id' => $org->id]);

        $this->trace($org, $admin, cost: 1.0, process: 'chatloop.answer', model: 'model-a', provider: 'openai');
        $this->trace($org, $other, cost: 2.0, process: 'chatloop.ask', model: 'model-b', provider: 'ollama');

        $month = $this->thisMonth();
        $consumption = $this->consumption();

        $byUser = new AiConsumptionFilters($month->from, $month->to, userId: $admin->id);
        $this->assertSame(1.0, $consumption->summary($org->id, $byUser)['known_cost_usd']);

        $byProcess = new AiConsumptionFilters($month->from, $month->to, process: 'chatloop.ask');
        $this->assertSame(2.0, $consumption->summary($org->id, $byProcess)['known_cost_usd']);

        $byModel = new AiConsumptionFilters($month->from, $month->to, model: 'model-a');
        $this->assertSame(1.0, $consumption->summary($org->id, $byModel)['known_cost_usd']);

        $byProvider = new AiConsumptionFilters($month->from, $month->to, provider: 'ollama');
        $this->assertSame(2.0, $consumption->summary($org->id, $byProvider)['known_cost_usd']);
    }

    public function test_filter_options_are_not_narrowed_by_the_active_dimension_filters(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 1.0, process: 'chatloop.answer', model: 'model-a', provider: 'openai');
        $this->trace($org, $user, cost: 2.0, process: 'chatloop.ask', model: 'model-b', provider: 'ollama');

        $month = $this->thisMonth();
        $filtered = new AiConsumptionFilters($month->from, $month->to, process: 'chatloop.ask');

        $available = $this->consumption()->availableFilters($org->id, $filtered);

        // Sans cela, choisir un process ferait disparaitre les autres options et
        // l'utilisateur ne pourrait plus revenir en arriere.
        $this->assertSame(['chatloop.answer', 'chatloop.ask'], $available['processes']);
        $this->assertSame(['model-a', 'model-b'], $available['models']);
        $this->assertSame(['ollama', 'openai'], $available['providers']);
    }

    // ------------------------------------------------------------------
    // Ventilations
    // ------------------------------------------------------------------

    public function test_breakdowns_keep_the_same_four_part_economic_shape(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 1.0, process: 'chatloop.answer');
        $this->trace($org, $user, cost: null, unknown: true, process: 'chatloop.answer');
        $this->trace($org, $user, cost: null, unknown: null, process: 'chatloop.answer');

        $rows = $this->consumption()->byProcess($org->id, $this->thisMonth());

        $this->assertCount(1, $rows);
        $this->assertSame('chatloop.answer', $rows[0]['key']);
        $this->assertSame(1.0, $rows[0]['known_cost_usd']);
        $this->assertSame(1, $rows[0]['measured_count']);
        $this->assertSame(1, $rows[0]['unknown_count']);
        $this->assertSame(1, $rows[0]['unevaluated_count']);
        $this->assertSame(3, $rows[0]['trace_count']);
    }

    public function test_the_daily_series_is_ordered_and_split_by_day(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $from = CarbonImmutable::parse('2026-03-01 00:00:00');

        $this->traceAt($org, $user, $from->addDays(2)->setTime(9, 0), cost: 2.0);
        $this->traceAt($org, $user, $from->setTime(8, 0), cost: 1.0);
        $this->traceAt($org, $user, $from->setTime(20, 0), cost: 0.5);

        $rows = $this->consumption()->byDay($org->id, new AiConsumptionFilters($from, $from->addMonth()));

        $this->assertCount(2, $rows);
        $this->assertSame('2026-03-01', substr($rows[0]['day'], 0, 10));
        $this->assertSame(1.5, $rows[0]['known_cost_usd']);
        $this->assertSame('2026-03-03', substr($rows[1]['day'], 0, 10));
        $this->assertSame(2.0, $rows[1]['known_cost_usd']);
    }

    public function test_every_breakdown_row_adds_up_to_its_call_count(): void
    {
        [$org, $user] = $this->organizationWithAdmin();

        $this->trace($org, $user, cost: 1.0, process: 'chatloop.answer', model: 'model-a', provider: 'openai');
        $this->trace($org, $user, cost: null, unknown: true, process: 'chatloop.answer', model: 'model-a', provider: 'openai');
        $this->trace($org, $user, cost: null, unknown: null, process: 'chatloop.ask', model: 'model-b', provider: null);

        $consumption = $this->consumption();
        $month = $this->thisMonth();

        $breakdowns = [
            'process' => $consumption->byProcess($org->id, $month),
            'model' => $consumption->byModel($org->id, $month),
            'provider' => $consumption->byProvider($org->id, $month),
            'user' => $consumption->byUser($org->id, $month),
            'day' => $consumption->byDay($org->id, $month),
        ];

        foreach ($breakdowns as $name => $rows) {
            foreach ($rows as $row) {
                // Une ligne de metrologie dont les parts ne font pas le total
                // est exactement l'incoherence silencieuse que cette console
                // combat : le lecteur croit qu'il manque des appels.
                $this->assertSame(
                    $row['trace_count'],
                    $row['measured_count'] + $row['unknown_count'] + $row['unevaluated_count'],
                    "la ventilation « {$name} » doit s'additionner",
                );
            }
        }

        $summary = $consumption->summary($org->id, $month);
        $this->assertSame(
            $summary['trace_count'],
            $summary['measured_count'] + $summary['unknown_count'] + $summary['unevaluated_count'],
        );
    }

    public function test_every_breakdown_table_shows_all_three_call_states(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->trace($organization, $admin, cost: 1.0);
        $this->trace($organization, $admin, cost: null, unknown: true);
        $this->trace($organization, $admin, cost: null, unknown: null);

        $content = $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk()->getContent();

        // Chaque tableau doit porter la colonne « non evalues », sinon ses
        // lignes ne s'additionnent pas a l'ecran meme si le read model, lui,
        // est juste.
        $tables = substr_count($content, 'data-consumption-row=');
        $unevaluatedHeaders = substr_count($content, __('ai.consumption_console_col_unevaluated'));

        $this->assertGreaterThanOrEqual(5, $tables, 'les cinq ventilations doivent etre rendues');
        $this->assertGreaterThanOrEqual(5, $unevaluatedHeaders, 'chaque ventilation doit exposer les trois etats');
    }

    // ------------------------------------------------------------------
    // La page : permissions, tenant, honnetete de l'affichage
    // ------------------------------------------------------------------

    public function test_a_non_admin_member_cannot_reach_the_console(): void
    {
        [$organization] = $this->organizationWithAdmin();
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($member)->get($this->consoleUrl($organization))->assertForbidden();
    }

    public function test_an_admin_of_another_organization_cannot_reach_this_console(): void
    {
        [$orgA] = $this->organizationWithAdmin();
        [, $adminB] = $this->organizationWithAdmin();

        $this->actingAs($adminB)->get($this->consoleUrl($orgA))->assertForbidden();
    }

    public function test_the_page_never_shows_another_organization_consumption(): void
    {
        [$orgA, $adminA] = $this->organizationWithAdmin();
        [$orgB, $userB] = $this->organizationWithAdmin();

        $this->trace($orgA, $adminA, cost: 1.0, model: 'model-of-a', provider: 'openai');
        $this->trace($orgB, $userB, cost: 500.0, model: 'model-of-b', provider: 'ollama');

        $response = $this->actingAs($adminA)->get($this->consoleUrl($orgA))->assertOk();

        $response->assertSee('model-of-a');
        $response->assertDontSee('model-of-b');
        $response->assertDontSee($userB->name);
        $this->assertSame(1.0, $response->viewData('summary')['known_cost_usd']);
    }

    public function test_the_page_renders_an_unmeasurable_cost_as_a_dash_never_as_zero(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->trace($organization, $admin, cost: null, unknown: true);

        $response = $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk();

        $this->assertNull($response->viewData('summary')['known_cost_usd']);
        // Le montant de tete doit etre « — » : afficher $0.000000 ferait passer
        // un cout non mesurable pour un appel gratuit.
        $this->assertMatchesRegularExpression(
            '/data-consumption-known-cost[^>]*>\s*—\s*</u',
            $response->getContent(),
        );
        $response->assertSee(__('ai.consumption_console_unknown_hint'));
    }

    public function test_the_page_states_on_screen_what_it_does_not_measure(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $response = $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk();

        // Ces limites ne doivent pas vivre seulement dans le code : un chiffre
        // d'observabilite sans ses reserves se lit comme une certitude.
        $response->assertSee(__('ai.consumption_console_scope_note'));
        $response->assertSee(__('ai.consumption_console_limit_cost_origin'));
        $response->assertSee(__('ai.consumption_console_limit_platform_price'));
        $response->assertSee(__('ai.consumption_console_limit_tokens'));
        $response->assertSee(__('ai.consumption_console_limit_credential'));
    }

    public function test_the_page_never_exposes_a_prompt_a_response_or_a_credential(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $trace = $this->trace($organization, $admin, cost: 1.0);
        $trace->forceFill([
            'prompt' => 'SECRET-PROMPT-SENTINEL',
            'response' => 'SECRET-RESPONSE-SENTINEL',
        ])->saveQuietly();

        $organization->aiSetting()->create([
            'provider' => 'openrouter',
            'model' => 'deepseek-chat',
            'api_key' => 'sk-SECRET-KEY-SENTINEL',
            'monthly_budget_usd' => 25.00,
        ]);

        $response = $this->actingAs($admin)->get($this->consoleUrl($organization))->assertOk();

        $response->assertDontSee('SECRET-PROMPT-SENTINEL');
        $response->assertDontSee('SECRET-RESPONSE-SENTINEL');
        $response->assertDontSee('SECRET-KEY-SENTINEL');
        // Le budget, lui, est une donnee de cadrage legitime.
        $response->assertSee('25.00');
    }

    public function test_the_period_filter_of_the_url_drives_the_page(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->traceAt($organization, $admin, CarbonImmutable::parse('2026-03-10 12:00:00'), cost: 7.0);
        $this->traceAt($organization, $admin, CarbonImmutable::parse('2026-04-10 12:00:00'), cost: 99.0);

        $response = $this->actingAs($admin)
            ->get($this->consoleUrl($organization).'?from=2026-03-01&to=2026-03-31')
            ->assertOk();

        $this->assertSame(7.0, $response->viewData('summary')['known_cost_usd']);
        $this->assertSame(1, $response->viewData('summary')['trace_count']);
    }

    public function test_an_unreadable_date_falls_back_to_the_current_month_without_breaking(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->trace($organization, $admin, cost: 4.0);

        // Une console d'observabilite ne doit pas casser sur un parametre d'URL
        // malforme, et ne doit pas deviner ce que l'utilisateur voulait dire.
        $response = $this->actingAs($admin)
            ->get($this->consoleUrl($organization).'?from=pas-une-date&to=non-plus')
            ->assertOk();

        $this->assertSame(4.0, $response->viewData('summary')['known_cost_usd']);
    }

    public function test_an_inverted_period_falls_back_to_the_current_month(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->trace($organization, $admin, cost: 4.0);

        $response = $this->actingAs($admin)
            ->get($this->consoleUrl($organization).'?from=2026-05-01&to=2026-01-01')
            ->assertOk();

        $this->assertSame(4.0, $response->viewData('summary')['known_cost_usd']);
    }

    public function test_the_console_is_reachable_from_the_organization_admin_navigation(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();

        $this->actingAs($admin)
            ->get($this->consoleUrl($organization))
            ->assertOk()
            ->assertSee(__('navigation.org_admin_ai_consumption'));
    }

    private function consoleUrl(Organization $organization): string
    {
        return route('organization.admin.ai-consumption', ['organization' => $organization->slug]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function consumption(): OrganizationAiConsumption
    {
        return app(OrganizationAiConsumption::class);
    }

    private function thisMonth(): AiConsumptionFilters
    {
        return AiConsumptionFilters::currentMonth();
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $organization->update(['admin_id' => $admin->id]);

        return [$organization->fresh(), $admin];
    }

    private function trace(
        Organization $organization,
        User $user,
        ?float $cost,
        ?bool $unknown = false,
        string $process = 'chatloop.answer',
        string $model = 'deepseek-chat',
        ?string $provider = 'openrouter',
    ): AiInteraction {
        return AiInteraction::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'process' => $process,
            'feature' => 'chatloop_ai_answer',
            'model' => $model,
            'prompt' => 'prompt',
            'response' => 'response',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => $cost,
            'cost_unknown' => $unknown,
            // `array_filter` reproduit le comportement reel des ecrivains :
            // provider null => cle absente du JSON, pas cle a null.
            'metadata' => array_filter(['provider' => $provider], static fn ($v) => $v !== null),
        ]);
    }

    /**
     * Trace posee a un instant precis.
     *
     * `created_at` n'est PAS dans `$fillable` : passe a `create()` il serait
     * ignore en silence et toutes les traces partageraient l'instant de
     * l'insertion (piege corrige en TASK-1218). On l'ecrit donc explicitement.
     */
    private function traceAt(
        Organization $organization,
        User $user,
        CarbonImmutable $at,
        ?float $cost,
        ?bool $unknown = false,
    ): AiInteraction {
        $trace = $this->trace($organization, $user, $cost, $unknown);
        $trace->forceFill(['created_at' => $at])->saveQuietly();

        return $trace->refresh();
    }
}
