<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 9 — chercher par nom de structure dans l'annuaire.
 *
 * ## Le piege de ce point
 *
 * Decocher la case n'efface AUCUNE structure deja saisie — c'est garde par un
 * test du point 2, et c'est voulu. Une Organization qui a essaye puis renonce
 * garde donc des valeurs en base. Une recherche non bornee les rendrait
 * trouvables alors qu'elles ne s'affichent plus nulle part.
 */
class TASK1762RechercheStructureAnnuaireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-recherche', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membre(array $surcharge): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'approved_at' => now(),
        ], $surcharge));
    }

    private function chercher(string $q): string
    {
        return $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org).'?q='.urlencode($q))
            ->assertOk()->getContent();
    }

    /** 🔴 On trouve quelqu'un par le nom de sa structure. */
    public function test_on_trouve_par_le_nom_de_la_structure(): void
    {
        $this->membre(['first_name' => 'Camille', 'name' => 'MOREAU', 'structure' => 'SCOP Oxalis']);
        $this->membre(['first_name' => 'Jean', 'name' => 'DUPONT', 'structure' => 'Association Les Amis']);

        $html = $this->chercher('Oxalis');

        $this->assertStringContainsString('Camille MOREAU', $html);
        $this->assertStringNotContainsString('Jean DUPONT', $html);
    }

    /** La recherche par prenom et par nom continue de marcher. */
    public function test_la_recherche_par_prenom_et_nom_n_est_pas_cassee(): void
    {
        $this->membre(['first_name' => 'Camille', 'name' => 'MOREAU', 'structure' => 'SCOP Oxalis']);
        $this->membre(['first_name' => 'Jean', 'name' => 'DUPONT', 'structure' => null]);

        $this->assertStringContainsString('Camille MOREAU', $this->chercher('Camille'));
        $this->assertStringContainsString('Jean DUPONT', $this->chercher('DUPONT'));
        $this->assertStringContainsString('Camille MOREAU', $this->chercher('Camille MOREAU'));
    }

    /** Insensible a la casse, et sur un fragment. */
    public function test_la_recherche_est_insensible_a_la_casse_et_partielle(): void
    {
        $this->membre(['first_name' => 'Camille', 'name' => 'MOREAU', 'structure' => 'SCOP Oxalis']);

        $this->assertStringContainsString('Camille MOREAU', $this->chercher('oxalis'));
        $this->assertStringContainsString('Camille MOREAU', $this->chercher('OXAL'));
    }

    /**
     * 🔴 LA GARDE : si l'Organization a ferme le champ, on ne cherche plus
     * dedans — meme si des valeurs restent en base.
     */
    public function test_une_organization_fermee_ne_laisse_pas_chercher_dans_les_structures(): void
    {
        $this->membre(['first_name' => 'Camille', 'name' => 'MOREAU', 'structure' => 'SCOP Oxalis']);

        $this->org->update(['structure_enabled' => false]);

        $html = $this->chercher('Oxalis');

        $this->assertStringNotContainsString('Camille MOREAU', $html);

        // Et la personne reste trouvable par son nom : c'est la recherche dans
        // la STRUCTURE qu'on ferme, pas l'annuaire.
        $this->assertStringContainsString('Camille MOREAU', $this->chercher('MOREAU'));
    }

    /** 🔴 Le texte d'invite dit ce que la recherche couvre vraiment. */
    public function test_le_texte_d_invite_suit_le_drapeau(): void
    {
        $avec = $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org))->assertOk()->getContent();

        $this->assertStringContainsString(__('directory.search_placeholder_with_structure'), $avec);

        $this->org->update(['structure_enabled' => false]);

        $sans = $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org))->assertOk()->getContent();

        $this->assertStringContainsString(__('directory.search_placeholder'), $sans);
        $this->assertStringNotContainsString(__('directory.search_placeholder_with_structure'), $sans);
    }

    /** 🔴 La recherche ne traverse pas les Organizations. */
    public function test_la_recherche_ne_traverse_pas_les_organizations(): void
    {
        $autre = Organization::factory()->create([
            'slug' => 'org-1762-voisine', 'is_active' => true, 'structure_enabled' => true,
        ]);

        User::factory()->create([
            'organization_id' => $autre->id, 'approved_at' => now(),
            'first_name' => 'Zoe', 'name' => 'VOISINE', 'structure' => 'SCOP Oxalis',
        ]);

        $html = $this->chercher('Oxalis');

        $this->assertStringNotContainsString('Zoe VOISINE', $html);
    }
}
