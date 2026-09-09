<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiInteractionFeedback;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\AiQualityInstrumentation;
use App\Support\Ai\AiQualityReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1487 (AI Quality Q2) — le cockpit qui distingue ZERO de NON MESURE.
 *
 * ## Le seul vrai danger de cet ecran
 *
 * Afficher « 0 % utile » sur zero retour. Ca se lit « l'IA n'aide personne »,
 * alors que la verite est « personne n'a jamais ete interroge ». Le CDC
 * l'interdit, et c'est exactement ce que la section B protege.
 *
 * ## Ce que la mesure du 2026-09-09 a impose
 *
 * 281 interactions, 10 fonctions, **2 instrumentees**, **0 verdict**. Le
 * cockpit honnete est donc beaucoup plus petit qu'espere : sa valeur n'est pas
 * de montrer la qualite, c'est de montrer que huit fonctions sur dix ne
 * peuvent pas etre evaluees. C'est la seule information vraie qu'il possede.
 *
 * ## La date d'instrumentation est un FAIT, a l'heure pres
 *
 * Une premiere version datait a minuit. Le rapport comptait alors 3 tours de
 * `clarify_help_request` comme evaluables, alors qu'ils avaient eu lieu deux
 * heures avant que le code n'existe. La section C fige la precision.
 */
class TASK1487AiQualityCockpitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1487-a', 'name' => 'Org 1487 A']);
        $this->b = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1487-b', 'name' => 'Org 1487 B']);

        $this->adminA = User::factory()->complete()->create(['organization_id' => $this->a->id]);
        $this->a->forceFill(['admin_id' => $this->adminA->id])->save();

        $this->adminB = User::factory()->complete()->create(['organization_id' => $this->b->id]);
        $this->b->forceFill(['admin_id' => $this->adminB->id])->save();

        $this->superAdmin = User::factory()->complete()->create(['organization_id' => $this->a->id, 'is_admin' => true]);

        $this->to = CarbonImmutable::now();
        $this->from = $this->to->subDays(30);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le tenant : A ne voit JAMAIS B
    // =====================================================================

    public function test_an_org_admin_never_sees_another_organization_interactions(): void
    {
        $this->interaction($this->a, 'clarify_help_request');
        $this->interaction($this->b, 'clarify_help_request');
        $this->interaction($this->b, 'blog_explorer');

        $report = app(AiQualityReport::class)->forOrganization($this->a, $this->from, $this->to);

        $this->assertSame(1, $report['interactions'], 'seules les interactions de A');
    }

    public function test_the_org_console_is_closed_to_an_admin_of_another_organization(): void
    {
        $this->actingAs($this->adminB)
            ->get(route('organization.admin.ai-quality', ['organization' => $this->a->slug]))
            // 403, pas 404 : c'est ce que rend `OrgAdminMiddleware`, mesure.
            ->assertStatus(403);
    }

    public function test_the_platform_console_is_closed_to_an_org_admin(): void
    {
        $this->actingAs($this->adminA)->get(route('admin.ai-quality'))->assertStatus(403);
    }

    public function test_the_super_admin_sees_the_whole_platform_and_can_filter(): void
    {
        $this->interaction($this->a, 'clarify_help_request');
        $this->interaction($this->b, 'clarify_help_request');

        $all = app(AiQualityReport::class)->forPlatform($this->from, $this->to);
        $this->assertSame(2, $all['interactions']);

        $onlyA = app(AiQualityReport::class)->forPlatform($this->from, $this->to, $this->a);
        $this->assertSame(1, $onlyA['interactions']);
    }

    // =====================================================================
    // B. JAMAIS un faux zero
    // =====================================================================

    /**
     * Sans un seul verdict, l'ecran ne montre AUCUN pourcentage de qualite —
     * il montre une phrase qui dit pourquoi.
     */
    public function test_without_any_feedback_no_percentage_is_shown(): void
    {
        $this->interaction($this->a, 'clarify_help_request');

        $html = $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-quality', ['organization' => $this->a->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-quality-no-feedback', $html);
        $this->assertStringContainsString(e(__('ai.quality_no_feedback_title')), $html);
    }

    /** Une couverture sans denominateur vaut `null`, jamais zero. */
    public function test_coverage_without_a_denominator_is_null_not_zero(): void
    {
        // Une fonction NON instrumentee : aucun tour evaluable, donc aucune
        // couverture calculable. `blog_generate` n'a aucun ecrivain de verdict —
        // contrairement a `loop_knowledge_answer`, branchee depuis TASK-1328.
        $this->interaction($this->a, 'blog_generate');

        $report = app(AiQualityReport::class)->forOrganization($this->a, $this->from, $this->to);
        $row = collect($report['rows'])->firstWhere('feature', 'blog_generate');

        $this->assertNull($row['coverage'], 'sans denominateur, la couverture n\'existe pas');
        $this->assertNotSame(0, $row['coverage']);
    }

    /** Et l'ecran la rend « — », jamais « 0 % ». */
    public function test_the_screen_renders_a_dash_never_a_zero_percent(): void
    {
        $this->interaction($this->a, 'blog_generate');

        $html = $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-quality', ['organization' => $this->a->slug]))
            ->assertOk()
            ->getContent();

        // Le seul « 0 % » tolere est celui de la phrase qui explique qu'on ne
        // l'affiche pas : on mesure donc la CELLULE, pas la page.
        preg_match_all('/data-ai-quality-coverage[^>]*>\s*([^<]+?)\s*</u', $html, $m);

        $this->assertNotEmpty($m[1], 'premisse : au moins une cellule de couverture');

        foreach ($m[1] as $cell) {
            $this->assertSame('—', trim($cell), 'une couverture inconnue se rend « — »');
        }
    }

    /**
     * La fiabilite et les refus ne sont PAS journalises. Le dire vaut mieux
     * que d'afficher « 100 % de succes » ou « 0 refus », vrais par accident.
     */
    public function test_reliability_is_declared_not_measured(): void
    {
        $html = $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-quality', ['organization' => $this->a->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-quality-reliability-unavailable', $html);
    }

    // =====================================================================
    // C. Les quatre statuts, et la date a l'HEURE pres
    // =====================================================================

    public function test_a_function_without_any_writer_is_not_instrumented(): void
    {
        $this->interaction($this->a, 'blog_generate');

        $this->assertSame(
            AiQualityInstrumentation::STATUS_NOT_INSTRUMENTED,
            $this->rowFor('blog_generate')['status'],
        );
    }

    /**
     * Instrumentee, mais tous les tours de la periode sont ANTERIEURS a
     * l'instrumentation : ils ne sont pas « non evalues », ils sont
     * inevaluables. Personne n'a jamais pu les juger.
     */
    public function test_turns_older_than_the_instrumentation_are_not_yet_measurable(): void
    {
        $since = AiQualityInstrumentation::since('clarify_help_request');
        $this->assertNotNull($since);

        $this->interaction($this->a, 'clarify_help_request', $since->subHour());

        $row = $this->rowFor('clarify_help_request');

        $this->assertSame(1, $row['interactions']);
        $this->assertSame(0, $row['evaluable'], 'un tour anterieur n\'est pas evaluable');
        $this->assertSame(AiQualityInstrumentation::STATUS_NOT_YET_MEASURABLE, $row['status']);
    }

    /**
     * UNE SECONDE plus tard, le meme tour devient evaluable. C'est la
     * precision qu'une date a minuit aurait perdue — et elle avait
     * effectivement fait compter 3 tours comme evaluables alors qu'ils
     * precedaient le code de deux heures.
     *
     * La seconde, et pas l'heure : la premiere version de ce test posait le
     * tour a `since + 1h`, ce qui tombait dans le FUTUR — l'instrumentation
     * datant de moins d'une heure — donc hors de la fenetre [from, to].
     */
    public function test_a_turn_after_the_instrumentation_becomes_measurable(): void
    {
        $since = AiQualityInstrumentation::since('clarify_help_request');

        $this->assertTrue($since->lessThan($this->to), 'premisse : l\'instrumentation est dans le passe');

        $this->interaction($this->a, 'clarify_help_request', $since->addSecond());

        $row = $this->rowFor('clarify_help_request');

        $this->assertSame(1, $row['evaluable']);
        $this->assertSame(AiQualityInstrumentation::STATUS_NO_FEEDBACK_YET, $row['status']);
    }

    /** Et un verdict le fait passer a MESURE. */
    public function test_one_verdict_makes_the_function_measured(): void
    {
        $since = AiQualityInstrumentation::since('clarify_help_request');
        $interaction = $this->interaction($this->a, 'clarify_help_request', $since->addSecond());

        AiInteractionFeedback::query()->create([
            'ai_interaction_id' => $interaction->id,
            'organization_id' => $this->a->id,
            'user_id' => $this->adminA->id,
            'verdict' => AiInteractionFeedback::VERDICT_HELPFUL,
        ]);

        $row = $this->rowFor('clarify_help_request');

        $this->assertSame(AiQualityInstrumentation::STATUS_MEASURED, $row['status']);
        $this->assertSame(1, $row['evaluated']);
        $this->assertSame(1, $row['helpful']);
        $this->assertSame(0, $row['improve']);
        $this->assertEqualsWithDelta(1.0, $row['coverage'], 0.0001, 'un tour evaluable, un verdict : couverture pleine');
    }

    /** Une fonction instrumentee mais jamais utilisee apparait quand meme. */
    public function test_an_instrumented_function_appears_even_with_no_interaction(): void
    {
        $features = collect($this->report()['rows'])->pluck('feature')->all();

        foreach (AiQualityInstrumentation::features() as $declared) {
            $this->assertContains($declared, $features, "[{$declared}] doit apparaitre meme sans trafic");
        }
    }

    // =====================================================================
    // D. Ce que ce cockpit n'est PAS
    // =====================================================================

    /**
     * **Jamais par utilisateur.** `ai_interaction_feedbacks.user_id` rend un
     * verdict attribuable a une personne ; grouper par personne transformerait
     * mecaniquement cet ecran en notation d'utilisateurs, ce que le CDC
     * interdit. La colonne n'est ni lue, ni groupee, ni rendue.
     */
    public function test_the_report_never_reads_or_groups_by_user(): void
    {
        $source = php_strip_whitespace(app_path('Support/Ai/AiQualityReport.php'));

        foreach (['user_id', "groupBy('f.user_id')", 'orderByDesc(\'evaluated\')->user'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "notation d'utilisateur : {$forbidden}");
        }

        $view = file_get_contents(resource_path('views/admin/partials/ai-quality-table.blade.php'));
        $this->assertStringNotContainsString('user', $view, 'aucune colonne utilisateur a l\'ecran');
    }

    /** Aucune conversation, aucun prompt, aucune reponse ne sort du rapport. */
    public function test_no_conversation_content_reaches_the_cockpit(): void
    {
        $since = AiQualityInstrumentation::since('clarify_help_request');
        $this->interaction($this->a, 'clarify_help_request', $since->addSecond(), 'SECRET-PROMPT-1487');

        $html = $this->actingAs($this->adminA)
            ->get(route('organization.admin.ai-quality', ['organization' => $this->a->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('SECRET-PROMPT-1487', $html);
        $this->assertStringNotContainsString('SECRET-RESPONSE-1487', $html);
    }

    /**
     * La declaration d'instrumentation doit correspondre au CODE REEL : toute
     * fonction declaree a un ecrivain de verdict, et tout ecrivain est declare.
     * Une liste ecrite a la main qui derive du code est un mensonge en sursis.
     */
    public function test_the_declaration_matches_the_real_writers(): void
    {
        $chatLoop = app_path('Services/ChatLoop/AiResponseExplanationService.php');

        $writers = [
            'blog_explorer' => app_path('Http/Controllers/BlogExplorerController.php'),
            'clarify_help_request' => app_path('Livewire/AiShell.php'),
            // Les cinq du ChatLoop partagent UN ecrivain, `submitFeedback()`.
            'loop_knowledge_answer' => $chatLoop,
            'loop_hybrid_answer' => $chatLoop,
            'chatloop_ai_ask' => $chatLoop,
            'chatloop_ai_answer' => $chatLoop,
            'chatloop_ai_summarize' => $chatLoop,
        ];

        $this->assertSame(
            collect(AiQualityInstrumentation::features())->sort()->values()->all(),
            collect(array_keys($writers))->sort()->values()->all(),
            'toute fonction declaree doit avoir un ecrivain identifie'
        );

        foreach ($writers as $feature => $path) {
            $source = php_strip_whitespace($path);
            $this->assertStringContainsString('AiInteractionFeedback', $source, "[{$feature}] son ecrivain doit vraiment ecrire un verdict");
        }
    }

    /**
     * Les dates d'instrumentation sont des FAITS, pas des journees.
     *
     * Trou trouve par sabotage : ramener `clarify_help_request` a minuit n'a
     * fait rougir AUCUN test — tous derivent de la constante (`since()->add…`),
     * donc aucun ne peut detecter qu'elle est fausse. Ils suivent le mensonge.
     *
     * Ce test regarde la constante elle-meme. Une date posee a exactement
     * 00:00:00 n'est pas une mesure, c'est un arrondi a la journee — et c'est
     * precisement cet arrondi qui avait fait compter 3 tours comme evaluables
     * alors qu'ils precedaient le code de deux heures.
     */
    public function test_the_instrumentation_dates_are_facts_not_days(): void
    {
        foreach (AiQualityInstrumentation::features() as $feature) {
            $since = AiQualityInstrumentation::since($feature);

            $this->assertNotNull($since, "[{$feature}]");

            $this->assertNotSame(
                '00:00:00',
                $since->format('H:i:s'),
                "[{$feature}] minuit pile n'est pas un horodatage de commit : c'est un arrondi a la journee"
            );

            $this->assertTrue($since->lessThan(CarbonImmutable::now()), "[{$feature}] une instrumentation future n'existe pas");
        }
    }

    /**
     * Et leur ORDRE est celui de l'histoire du produit : le blog explorer
     * (TASK-1256) precede le Shell (TASK-1486) de trois semaines.
     */
    public function test_the_blog_explorer_was_instrumented_before_the_shell(): void
    {
        $this->assertTrue(
            AiQualityInstrumentation::since('blog_explorer')
                ->lessThan(AiQualityInstrumentation::since('clarify_help_request')),
        );
    }

    /** Aucune migration : le cockpit LIT, il ne cree rien. */
    public function test_no_migration_ships_with_this_cockpit(): void
    {
        $this->assertSame([], glob(database_path('migrations/*ai_quality*.php')) ?: []);
        $this->assertSame([], glob(database_path('migrations/*quality_cockpit*.php')) ?: []);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(AiQualityReport::class)->forOrganization($this->a, $this->from, $this->to);
    }

    /** @return array<string, mixed> */
    private function rowFor(string $feature): array
    {
        $row = collect($this->report()['rows'])->firstWhere('feature', $feature);

        $this->assertNotNull($row, "[{$feature}] absente du rapport");

        return $row;
    }

    private function interaction(Organization $organization, string $feature, ?CarbonImmutable $at = null, string $prompt = 'Une question.'): AiInteraction
    {
        $interaction = AiInteraction::query()->create([
            'user_id' => $organization->id === $this->a->id ? $this->adminA->id : $this->adminB->id,
            'organization_id' => $organization->id,
            'feature' => $feature,
            'model' => 'gpt-4o-mini',
            'prompt' => $prompt,
            'response' => 'SECRET-RESPONSE-1487',
            'input_tokens' => 10,
            'output_tokens' => 10,
        ]);

        if ($at !== null) {
            $interaction->forceFill(['created_at' => $at])->save();
        }

        return $interaction->fresh();
    }
}
