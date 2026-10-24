<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AN — un lien vers le tableau de bord de l'Organization par défaut.
 *
 * **Demande 14 de Cyril**, précisée : « un lien vers les dashboard admin
 * organisation scopée, exemple `/org/main/admin/`, en dessous de Tableau de
 * bord » — puis « il faut que ce soit un lien vers l'organisation principale
 * par défaut ».
 *
 * ## Aucune règle nouvelle
 *
 * L'Organization vient de `DefaultOrganizationResolver`, l'autorité qui répond
 * déjà à cette question partout ailleurs : celle marquée par défaut, et à
 * défaut la première active. Réécrire ce choix ici aurait créé une seconde
 * définition de « l'organisation principale ».
 *
 * ## Le lien N'EXISTE PAS quand il n'y a rien à pointer
 *
 * C'est la condition que j'ai demandée à Cyril avant d'écrire. Une base sans
 * aucune Organization — installation neuve, test — produirait sinon une URL à
 * paramètre manquant : **une erreur de rendu sur toutes les pages de
 * l'administration**, pour un lien d'agrément.
 */
class TASK1694AdminNavOrgDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function html(): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();
    }

    /** LE lien demandé, vers l'Organization marquée par défaut. */
    public function test_the_rail_links_to_the_default_organization_dashboard(): void
    {
        Organization::factory()->create(['slug' => 'autre-1694an', 'is_active' => true, 'is_default' => false]);
        $defaut = Organization::factory()->create(['slug' => 'principale-1694an', 'is_active' => true, 'is_default' => true]);

        $html = $this->html();

        $this->assertStringContainsString('data-admin-nav-org-dashboard="principale-1694an"', $html);
        $this->assertStringContainsString(
            route('organization.admin.dashboard', ['organization' => $defaut->slug]),
            $html,
        );
    }

    /**
     * Et jamais vers une AUTRE Organization.
     *
     * Sans cette moitié, un lien pointant la première venue passerait le test
     * précédent dès que l'ordre des lignes le favorise.
     */
    public function test_it_never_points_at_another_organization(): void
    {
        $autre = Organization::factory()->create(['slug' => 'autre-1694an', 'is_active' => true, 'is_default' => false]);
        Organization::factory()->create(['slug' => 'principale-1694an', 'is_active' => true, 'is_default' => true]);

        $html = $this->html();

        $this->assertStringNotContainsString('data-admin-nav-org-dashboard="autre-1694an"', $html);
        $this->assertSame(1, substr_count($html, 'data-admin-nav-org-dashboard='));
    }

    /**
     * Il est posé SOUS « Tableau de bord », comme demandé.
     *
     * Mesuré sur l'ordre rendu : c'est lui qui se parcourt à l'œil.
     */
    public function test_it_sits_right_below_the_platform_dashboard(): void
    {
        Organization::factory()->create(['slug' => 'principale-1694an', 'is_active' => true, 'is_default' => true]);

        $html = $this->html();

        $tableauDeBord = strpos($html, '<!-- Tableau de bord (standalone) -->');
        $lienOrg = strpos($html, 'data-admin-nav-org-dashboard=');

        $this->assertNotFalse($tableauDeBord);
        $this->assertNotFalse($lienOrg);
        $this->assertLessThan($lienOrg, $tableauDeBord);
    }

    /**
     * Sans Organization du tout, la page se rend QUAND MÊME.
     *
     * C'est la condition posée avant d'écrire. Sans la garde, `route()` lèverait
     * une erreur de paramètre manquant — sur **toutes** les pages de
     * l'administration, puisque le rail est dans le gabarit commun.
     */
    public function test_without_any_organization_the_admin_still_renders(): void
    {
        Organization::query()->delete();

        $html = $this->html();

        $this->assertStringNotContainsString('data-admin-nav-org-dashboard=', $html);
    }

    /**
     * Et sans Organization PAR DÉFAUT, le résolveur retombe sur la première
     * active — pas sur rien.
     *
     * C'est le comportement de `DefaultOrganizationResolver`, pas une règle
     * inventée ici : le test le constate plutôt que de le supposer.
     */
    public function test_without_a_default_it_falls_back_to_the_first_active(): void
    {
        // La factory d'utilisateur cree sa PROPRE Organization : sans ce
        // nettoyage, c'est elle la « premiere active », et le test mesurait un
        // etat qu'il n'avait pas pose. Premiere version rouge pour cette raison
        // — ma premisse, pas le code.
        Organization::query()->delete();

        Organization::factory()->create(['slug' => 'active-1694an', 'is_active' => true, 'is_default' => false]);

        $this->assertStringContainsString('data-admin-nav-org-dashboard="active-1694an"', $this->html());
    }
}
