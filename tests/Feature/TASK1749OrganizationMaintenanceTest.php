<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — le « Mode maintenance » devient REEL.
 *
 * ## Ce que ce fichier ferme
 *
 * `organizations.maintenance_mode` etait ECRITE par l'ecran d'administration et
 * AFFICHEE en badge, mais lue par RIEN : aucun middleware, aucun controleur,
 * aucune garde, aucun test. Mesure du 04/11 sur tout `app/`, `routes/` et
 * `tests/`.
 *
 * La case promettait « seuls les admins peuvent acceder a la plateforme de
 * cette organisation » et ne bloquait personne. Un administrateur qui croyait
 * avoir ferme son Organization la laissait grande ouverte.
 *
 * ## Les deux defauts que ces tests ont attrapes a l'ecriture
 *
 * Ils ne sont pas theoriques — les deux etaient dans ma premiere version :
 *
 *  1. la page de connexion de l'Organization rendait 503 elle aussi. Un
 *     SuperAdmin non connecte ne pouvait donc plus s'authentifier pour venir
 *     voir. J'avais cru a tort que ce sous-groupe vivait hors du groupe
 *     prefixe ;
 *  2. un visiteur ANONYME sur une surface protegee recevait 302 vers la
 *     connexion au lieu de la page de maintenance, parce que `Authenticate`
 *     figure dans la liste de priorite du framework et passait devant.
 */
class TASK1749OrganizationMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $fermee;

    private Organization $ouverte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fermee = Organization::factory()->create([
            'slug' => 'org-fermee-1749',
            'is_active' => true,
            'is_public' => true,
            'maintenance_mode' => true,
        ]);

        $this->ouverte = Organization::factory()->create([
            'slug' => 'org-ouverte-1749',
            'is_active' => true,
            'is_public' => true,
            'maintenance_mode' => false,
        ]);
    }

    private function membreDe(Organization $organization, bool $superAdmin = false): User
    {
        return User::factory()->create([
            'organization_id' => $organization->id,
            'is_admin' => $superAdmin,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_la_vitrine_publique_annonce_la_maintenance(): void
    {
        $this->get(route('organization.home', $this->fermee))
            ->assertStatus(503)
            ->assertSee('data-org-maintenance', false)
            ->assertSee($this->fermee->name);
    }

    /**
     * Le defaut n°2 ci-dessus. Sans la priorite de middleware, `Authenticate`
     * redirigeait vers la connexion AVANT que la maintenance ne puisse se dire.
     */
    public function test_un_visiteur_anonyme_voit_la_maintenance_et_non_une_redirection(): void
    {
        $this->get(route('organization.loops.index', $this->fermee))
            ->assertStatus(503);
    }

    public function test_un_membre_de_l_organization_fermee_ne_passe_pas(): void
    {
        $this->actingAs($this->membreDe($this->fermee))
            ->get(route('organization.loops.index', $this->fermee))
            ->assertStatus(503);
    }

    /**
     * La SEULE exemption, et elle est celle que `User::canAccessOrganization()`
     * connait deja : le SuperAdmin PLATEFORME. Pas l'administrateur de
     * l'Organization — on ne lui invente pas une seconde exemption.
     */
    public function test_le_superadmin_passe(): void
    {
        $this->actingAs($this->membreDe($this->ouverte, superAdmin: true))
            ->get(route('organization.home', $this->fermee))
            ->assertOk();
    }

    /**
     * Le defaut n°1 ci-dessus, et c'est le plus couteux : sans cette exemption,
     * un SuperAdmin non connecte n'avait plus AUCUN moyen d'entrer.
     */
    public function test_les_portes_d_authentification_restent_joignables(): void
    {
        $this->get(route('organization.login', $this->fermee))->assertOk();
        $this->get(route('organization.register', $this->fermee))->assertOk();
        $this->get(route('organization.password.request', $this->fermee))->assertOk();
    }

    public function test_une_organization_OUVERTE_n_est_pas_touchee(): void
    {
        $this->get(route('organization.home', $this->ouverte))->assertOk();

        $this->actingAs($this->membreDe($this->ouverte))
            ->get(route('organization.loops.index', $this->ouverte))
            ->assertOk();
    }

    /**
     * Nommer une Organization PRIVEE a qui n'en fait pas partie confirmerait son
     * existence — meme discipline que le 403 de TASK-1483. Le titre devient
     * neutre.
     */
    public function test_une_organization_PRIVEE_fermee_ne_dit_pas_son_nom(): void
    {
        $privee = Organization::factory()->create([
            'slug' => 'org-privee-1749',
            'is_active' => true,
            'is_public' => false,
            'maintenance_mode' => true,
            'name' => 'Nom Qui Ne Doit Pas Fuir',
        ]);

        $this->get(route('organization.loops.index', $privee))
            ->assertStatus(503)
            ->assertDontSee('Nom Qui Ne Doit Pas Fuir');
    }

    /**
     * La page ne doit PAS monter le layout du tenant qu'elle vient de fermer :
     * il porterait son `header_javascript` rendu en brut, son rail de
     * navigation et le Shell IA — toute la machinerie qu'on declare arretee.
     */
    public function test_la_page_n_emprunte_pas_le_layout_du_tenant(): void
    {
        $html = $this->get(route('organization.home', $this->fermee))
            ->assertStatus(503)
            ->getContent();

        $this->assertStringNotContainsString('data-app-side-nav', $html);
        $this->assertStringNotContainsString('x-mobile-topbar', $html);
    }
}
