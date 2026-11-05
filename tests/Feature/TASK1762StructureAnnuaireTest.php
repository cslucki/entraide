<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 5 — la structure sur les cartes de l'annuaire.
 *
 * Sous le prenom et le nom, AVANT la localisation, en gras et dans la couleur
 * vive du theme.
 */
class TASK1762StructureAnnuaireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-annuaire', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membre(array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU',
            'approved_at' => now(),
        ], $surcharge));
    }

    private function annuaire(): string
    {
        return $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org))
            ->assertOk()->getContent();
    }

    /** La structure est sur la carte. */
    public function test_la_structure_apparait_sur_la_carte(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis']);

        $html = $this->annuaire();

        $this->assertStringContainsString('data-member-structure', $html);
        $this->assertStringContainsString('SCOP Oxalis', $html);
    }

    /**
     * 🔴 SOUS le nom et AVANT la localisation — c'est la demande, et une simple
     * assertion de presence passerait avec la structure n'importe ou.
     */
    public function test_elle_est_sous_le_nom_et_avant_la_localisation(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis', 'city' => 'Marseille', 'country_code' => 'FR']);

        $html = $this->annuaire();

        $positionNom = strpos($html, 'Camille MOREAU');
        $positionStructure = strpos($html, 'data-member-structure');
        $positionLieu = strpos($html, 'Marseille');

        $this->assertNotFalse($positionNom);
        $this->assertNotFalse($positionStructure);
        $this->assertNotFalse($positionLieu, 'La localisation doit etre affichee pour que ce test mesure quelque chose.');

        $this->assertLessThan($positionStructure, $positionNom, 'La structure doit suivre le nom.');
        $this->assertLessThan($positionLieu, $positionStructure, 'La structure doit preceder la localisation.');
    }

    /** 🔴 En GRAS et dans la couleur du THEME, pas un hexadecimal en dur. */
    public function test_elle_est_en_gras_et_dans_la_couleur_du_theme(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis']);

        $html = $this->annuaire();

        preg_match('/<p class="([^"]*)"[^>]*data-member-structure/', $html, $m);

        $this->assertNotEmpty($m, 'La ligne de structure est introuvable.');
        $this->assertStringContainsString('font-bold', $m[1]);
        $this->assertStringContainsString('text-[var(--bp-accent)]', $m[1]);
        $this->assertStringNotContainsString('#', $m[1]);
    }

    /** 🔴 Rien si l'Organization n'a pas active le champ. */
    public function test_rien_si_l_organization_ne_l_a_pas_active(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis']);
        $this->org->update(['structure_enabled' => false]);

        $html = $this->annuaire();

        $this->assertStringNotContainsString('data-member-structure', $html);
        $this->assertStringNotContainsString('SCOP Oxalis', $html);
    }

    /** Et rien non plus pour qui n'a rien renseigne : pas de ligne vide. */
    public function test_rien_pour_un_membre_sans_structure(): void
    {
        $this->membre(['structure' => null]);

        $this->assertStringNotContainsString('data-member-structure', $this->annuaire());
    }

    /** Une seule ligne par membre qui en a une. */
    public function test_une_ligne_par_membre_concerne(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis']);
        $this->membre(['structure' => 'Association Les Amis', 'first_name' => 'Jean', 'name' => 'DUPONT']);
        $this->membre(['structure' => null, 'first_name' => 'Zoe', 'name' => 'LEROY']);

        $html = $this->annuaire();

        $this->assertSame(2, substr_count($html, 'data-member-structure'));
    }
}
