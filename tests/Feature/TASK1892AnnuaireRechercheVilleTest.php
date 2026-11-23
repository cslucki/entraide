<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1892 — la saisie libre de l'annuaire cherche aussi dans la VILLE.
 *
 * Mesure du 23/11 sur le jumeau : « Menton » ne rendait personne alors qu'un
 * membre y habite ; seul le menu « Localisation » (ville exacte) le trouvait.
 */
class TASK1892AnnuaireRechercheVilleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1892', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id, 'city' => null, 'first_name' => 'Visiteur', 'name' => 'NEUTRE']);
    }

    private function membre(array $surcharge): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id, 'approved_at' => now(), 'bio' => 'Rien a signaler.', 'slogan' => null,
        ], $surcharge));
    }

    private function chercher(string $q): string
    {
        return $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org).'?q='.urlencode($q))
            ->assertOk()->getContent();
    }

    public function test_on_trouve_un_membre_par_sa_ville_partielle_et_sans_casse(): void
    {
        $this->membre(['first_name' => 'Vincent', 'name' => 'PAILLIEZ', 'city' => 'Menton']);
        $this->membre(['first_name' => 'Marie', 'name' => 'REVELLE', 'city' => 'MARSEILLE']);

        $html = $this->chercher('menton');
        $this->assertStringContainsString('Vincent PAILLIEZ', $html);
        $this->assertStringNotContainsString('Marie REVELLE', $html);

        $html = $this->chercher('Marseil');
        $this->assertStringContainsString('Marie REVELLE', $html);
        $this->assertStringNotContainsString('Vincent PAILLIEZ', $html);
    }

    public function test_la_ville_ne_traverse_pas_les_organizations(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-1892-voisine', 'is_active' => true]);
        User::factory()->create(['organization_id' => $autre->id, 'approved_at' => now(), 'first_name' => 'Zoe', 'name' => 'VOISINE', 'city' => 'Menton']);

        $this->assertStringNotContainsString('Zoe VOISINE', $this->chercher('Menton'));
    }

    public function test_le_texte_d_invite_annonce_la_ville(): void
    {
        $this->assertStringContainsString('une ville', $this->chercher(''));
    }
}
