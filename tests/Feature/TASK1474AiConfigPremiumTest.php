<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Models\User;
use App\Support\GuestShell\GuestShellDiagnosis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1474 — `/admin/ai-config` devient lisible.
 *
 * ## Le probleme etait une hauteur, et elle se mesure
 *
 * A 1280x900, la page faisait **8119 px, soit 9 ecrans**. L'objectif du CDC —
 * « comprendre l'etat en moins de 10 secondes » — etait structurellement hors
 * d'atteinte.
 *
 * La cause n'etait pas le style : TROIS sections empilaient le meme motif, un
 * bloc DEPLIE par Organization. A elles seules : Shell Welcome 3453 px, Blog
 * 1754 px, Agents profil 1628 px — 84 % de la page.
 *
 * ## Ce qui a ete fait, et ce qui ne l'a pas ete
 *
 * Chaque bloc se replie (`<details>`), avec sur la ligne fermee ce qu'un
 * SuperAdmin doit voir sans cliquer : le nom, le diagnostic, le provider, le
 * mode, le cout du mois. Une Organization qui demande une ACTION s'ouvre
 * d'elle-meme — c'est la seule chose qu'on veut lire tout de suite.
 *
 * Plus un resume de tete, compte des diagnostics que `GuestShellDiagnosis`
 * produit deja.
 *
 * **Aucun composant generique, aucune palette nouvelle, aucun refactor des
 * 95 vues admin, aucune logique economique deplacee.** `<details>` est un
 * element HTML : pas de JavaScript, pas d'abstraction, et l'etat ouvert
 * survit a l'impression.
 *
 * ## Ce que ce test garde
 *
 * L'EDITION. Replier une section est sans interet si l'on perd la capacite de
 * regler une politique — les 28 formulaires doivent rester, avec leurs actions
 * et leurs champs.
 */
class TASK1474AiConfigPremiumTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'slug' => 'org-premium',
            'name' => 'Org Premium',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1474',
        ]);

        OrganizationGuestShellPolicy::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            ['enabled' => true],
        );

        $this->superAdmin = User::factory()->complete()->create([
            'organization_id' => $organization->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => null]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le resume de tete
    // =====================================================================

    public function test_the_summary_counts_the_diagnoses_the_authority_already_produces(): void
    {
        // Une seconde Organization, eteinte : deux diagnostics differents.
        $off = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-premium-off']);
        OrganizationGuestShellPolicy::query()->updateOrCreate(['organization_id' => $off->id], ['enabled' => false]);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        // Le CONTENEUR, pas ses enfants : `data-guest-shell-summary` est un
        // prefixe de `data-guest-shell-summary-item`, et une assertion sur la
        // sous-chaine serait satisfaite par les tuiles alors meme que le bloc
        // aurait disparu. Mesure faite : le sabotage passait.
        $this->assertMatchesRegularExpression('/<div[^>]*\sdata-guest-shell-summary>/', $html, 'le bloc de resume est rendu');

        // Le plafond plateforme n'est pas pose : le resume doit le DIRE, c'est
        // la premiere chose qui empeche toute activation.
        $this->assertStringContainsString('data-guest-shell-summary-item="platform_ceiling"', $html);
        $this->assertStringContainsString('data-guest-shell-summary-value="unset"', $html);

        // Et les comptes viennent des diagnostics, pas d'un calcul parallele.
        $this->assertSame(1, preg_match('/data-guest-shell-summary-item="action" data-guest-shell-summary-value="(\d+)"/', $html, $action));
        $this->assertSame(1, preg_match('/data-guest-shell-summary-item="disabled" data-guest-shell-summary-value="(\d+)"/', $html, $disabled));
        $this->assertSame('1', $action[1], 'une Organization en action requise (plafond non pose)');
        $this->assertSame('1', $disabled[1], 'une Organization eteinte');
    }

    public function test_the_summary_reflects_a_ceiling_once_it_is_set(): void
    {
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 12.5]);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-guest-shell-summary-value="set"', $html);
        $this->assertStringNotContainsString('data-guest-shell-summary-value="unset"', $html);
    }

    // =====================================================================
    // B. Chaque Organization se replie — sauf celle qui demande une action
    // =====================================================================

    public function test_every_per_organization_block_folds(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        // Les trois sections qui empilaient un bloc par Organization.
        $this->assertMatchesRegularExpression('/<details[^>]*data-guest-shell-row=/', $html, 'Shell Welcome');
        $this->assertMatchesRegularExpression('/<details[^>]*data-ai-config-row="blog:/', $html, 'Blog');
        $this->assertMatchesRegularExpression('/<details[^>]*data-ai-config-row="profile:/', $html, 'Agents profil');
    }

    /**
     * Ce qui s'ouvre tout seul n'est pas arbitraire : seule une Organization
     * qui demande une ACTION merite le scroll d'un SuperAdmin.
     */
    public function test_only_the_organization_that_needs_an_action_opens_itself(): void
    {
        $off = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-premium-off']);
        OrganizationGuestShellPolicy::query()->updateOrCreate(['organization_id' => $off->id], ['enabled' => false]);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        preg_match_all('/<details[^>]*data-guest-shell-row="([a-z0-9-]+)"([^>]*)>/', $html, $rows, PREG_SET_ORDER);
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $isOpen = str_contains($row[2], 'open');
            $expected = $row[1] === 'org-premium';

            $this->assertSame($expected, $isOpen, "[{$row[1]}] : ouverture attendue = ".var_export($expected, true));
        }
    }

    /** La ligne repliee dit deja l'essentiel : diagnostic, provider, mode, cout. */
    public function test_the_folded_line_already_says_what_matters(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<summary[^>]*>(.*?)<\/summary>/s', $html, $summary));

        foreach (['data-guest-shell-diag=', 'data-guest-shell-summary-provider', 'data-guest-shell-summary-mode', 'data-guest-shell-summary-cost'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle.' doit etre lisible sans deplier');
        }
    }

    // =====================================================================
    // C. L'edition n'a rien perdu
    // =====================================================================

    /**
     * Replier une section est sans interet si l'on perd le reglage. Les trois
     * formulaires par Organization restent, avec leurs actions et leurs champs.
     */
    public function test_editing_is_untouched(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        foreach (['admin.ai-config.guest-shell', 'admin.ai-config.blog', 'admin.ai-config.profile'] as $route) {
            $this->assertStringContainsString('action="'.route($route).'"', $html, $route);
        }

        foreach (['name="enabled"', 'name="display_mode"', 'name="max_messages"', 'name="retention_days"', 'name="guest_monthly_budget_usd"', 'name="organization_id"'] as $field) {
            $this->assertStringContainsString($field, $html, $field.' : le reglage doit rester possible');
        }
    }

    /** Et le formulaire vit DANS le bloc replie, pas a cote — sinon il serait toujours deplie. */
    public function test_each_form_lives_inside_its_folding_block(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<details[^>]*data-guest-shell-row="org-premium"[^>]*>.*?<form[^>]*action="'.preg_quote(route('admin.ai-config.guest-shell'), '/').'"/s', $html),
            'le formulaire de reglage est bien a l\'interieur du bloc repliable',
        );
    }

    // =====================================================================
    // D. Aucune autorite deplacee
    // =====================================================================

    /**
     * Le resume compte des diagnostics ; il ne recalcule ni budget, ni cout, ni
     * etat. Si un jour quelqu'un ecrit une seconde regle economique dans cette
     * vue, ce test ne le verra pas — mais la revue, si : c'est pourquoi la vue
     * ne doit contenir aucun appel a une autorite economique.
     */
    public function test_the_view_does_not_reach_for_an_economic_authority(): void
    {
        $view = (string) file_get_contents(resource_path('views/admin/ai-config/index.blade.php'));

        foreach (['AiEconomicGuard', 'ProviderResolver', 'AiProviderInvocation::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view, $forbidden.' n\'a rien a faire dans cette vue');
        }

        // Le diagnostic, lui, est bien la seule source des libelles d'etat :
        // la vue l'appelle, et n'appelle rien d'autre pour qualifier un etat.
        $this->assertStringContainsString('GuestShellDiagnosis::for(', $view);
        $this->assertStringNotContainsString('guest_shell_state_', $view, 'plus aucun libelle d\'etat hors du diagnostic');
    }
}
