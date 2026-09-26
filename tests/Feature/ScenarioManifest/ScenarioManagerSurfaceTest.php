<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1646 — la surface `/admin/outils/scenarios` et la navigation.
 *
 * Trois choses se prouvent ici, qu'un commentaire ne suffirait pas a garantir :
 *
 * 1. l'ecran est reserve au SuperAdmin — et « reserve » se teste avec un
 *    membre ordinaire, pas seulement avec un visiteur anonyme ;
 * 2. l'ecran ne MUTE rien : aucune route `admin.outils.scenarios*` n'accepte
 *    autre chose qu'un GET. « Lecture seule » est une promesse verifiable ;
 * 3. l'entree a bien QUITTE la section IA du rail pour la section Outils
 *    (CDC 5.1). Un deplacement de menu qui ne retire pas l'ancienne entree
 *    n'est pas un deplacement : c'est un doublon.
 */
class ScenarioManagerSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $membre;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
    }

    // =====================================================================
    // Acces
    // =====================================================================

    public function test_un_visiteur_anonyme_est_redirige(): void
    {
        $this->get(route('admin.outils.scenarios'))->assertRedirect();
    }

    public function test_un_membre_ordinaire_est_refuse(): void
    {
        // Le Scenario Manager est une surface SuperAdmin (CDC 4.1). L'autorite
        // est l'attribut `is_admin`, jamais l'appartenance a une Organization.
        $this->actingAs($this->membre)
            ->get(route('admin.outils.scenarios'))
            ->assertForbidden();
    }

    public function test_un_orgadmin_qui_n_est_pas_superadmin_est_refuse(): void
    {
        // Un administrateur d'Organization n'est PAS un SuperAdmin : cet ecran
        // voit toutes les definitions de la plateforme.
        $orgAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
        $this->organization->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertForbidden();
    }

    public function test_le_superadmin_ouvre_l_ecran(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.title'));
    }

    // =====================================================================
    // Lecture seule
    // =====================================================================

    public function test_aucune_route_scenarios_n_accepte_autre_chose_qu_un_get(): void
    {
        $fautives = [];

        foreach (Route::getRoutes() as $route) {
            $nom = $route->getName();

            if ($nom === null || ! str_starts_with($nom, 'admin.outils.scenarios')) {
                continue;
            }

            $verbes = array_diff($route->methods(), ['GET', 'HEAD']);

            if ($verbes !== []) {
                $fautives[$nom] = implode(',', $verbes);
            }
        }

        $this->assertSame(
            [],
            $fautives,
            'T1646 livre la fondation, pas le CRUD : aucune route ne doit muter quoi que ce soit.'
        );
    }

    public function test_l_ecran_annonce_franchement_qu_il_n_est_qu_une_fondation(): void
    {
        // Mieux vaut un bandeau explicite qu'un ecran qui laisse croire a des
        // actions absentes.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.foundation_notice'));
    }

    // =====================================================================
    // Ce que l'ecran montre
    // =====================================================================

    public function test_l_ecran_vide_le_dit(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->assertSee(__('admin.scenario_manager.empty'));
    }

    public function test_l_ecran_distingue_les_trois_etats_dont_un_derive(): void
    {
        $load = ScenarioPackLoad::create([
            'pack_id' => 'manifest-ofsh',
            'pack_version' => '1.0.0',
            'organization_id' => $this->organization->id,
            'loaded_at' => now(),
        ]);

        $commun = [
            'scenario_key' => 'ofsh',
            'name' => 'OFSH',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'json_source' => '{"manifest_version":1}',
            'created_by' => $this->superAdmin->id,
        ];

        ScenarioManifestVersion::create($commun + ['version' => '1.0.0', 'state' => ScenarioManifestVersion::STATE_VALID, 'scenario_pack_load_id' => $load->id]);
        ScenarioManifestVersion::create($commun + ['version' => '1.1.0', 'state' => ScenarioManifestVersion::STATE_VALID]);
        ScenarioManifestVersion::create($commun + ['version' => '1.2.0', 'state' => ScenarioManifestVersion::STATE_DRAFT]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        foreach (['1.0.0', '1.1.0', '1.2.0', 'ofsh'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }

        // Le compteur derive doit voir exactement une version chargee.
        $this->assertSame(1, ScenarioManifestVersion::query()->loaded()->count());
        $this->assertStringContainsString(__('admin.scenario_manager.state_loaded'), $html);
    }

    // =====================================================================
    // Navigation : le deplacement, prouve dans les deux sens
    // =====================================================================

    public function test_le_rail_affiche_outils_scenarios(): void
    {
        // Critere d'acceptation 1 du CDC : « le rail affiche Outils -> Scenarios ».
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.outils.scenarios'), $html);
        $this->assertStringContainsString(__('admin.scenario_manager.nav_label'), $html);
    }

    public function test_le_rail_expose_toujours_le_moteur_legacy(): void
    {
        // CDC 6.4 et 32.1 : le legacy reste accessible. Supprimer son entree
        // transformerait un lien vivant en lien mort.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.scenario-packs'), $html);
        $this->assertStringContainsString(__('admin.scenario_manager.legacy_nav_label'), $html);
    }

    public function test_scenario_packs_a_quitte_la_section_ia_du_rail(): void
    {
        // Le deplacement se verifie a la SOURCE du rail : dans le HTML rendu,
        // les deux sections se suivent et une assertion de position serait
        // fragile. Ici on prouve que l'entree n'est plus declaree dans
        // `$iaItems`, et qu'elle l'est bien dans `$outilsItems`.
        $rail = file_get_contents(resource_path('views/layouts/admin.blade.php'));

        $this->assertNotFalse($rail);

        $blocIa = $this->blocDeclaratif($rail, '$iaItems');
        $blocOutils = $this->blocDeclaratif($rail, '$outilsItems');

        $this->assertStringNotContainsString(
            "'admin.scenario-packs'",
            $blocIa,
            'Un scenario est un outil, pas une sous-fonction de l IA (CDC 5.1) : l entree ne doit plus etre declaree dans la section IA.'
        );

        $this->assertStringContainsString("'admin.scenario-packs'", $blocOutils);
        $this->assertStringContainsString("'admin.outils.scenarios'", $blocOutils);
    }

    /**
     * Extrait la portion du rail qui DECLARE un groupe de menu, de sa premiere
     * mention jusqu'a la fin du bloc `@php` qui la contient.
     */
    private function blocDeclaratif(string $rail, string $variable): string
    {
        $debut = strpos($rail, $variable);
        $this->assertNotFalse($debut, "Le groupe {$variable} doit exister dans le rail.");

        $fin = strpos($rail, '@endphp', $debut);
        $this->assertNotFalse($fin, "Le groupe {$variable} doit etre declare dans un bloc @php.");

        return substr($rail, $debut, $fin - $debut);
    }
}
