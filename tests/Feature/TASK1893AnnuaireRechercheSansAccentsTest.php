<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1893 — la recherche de l'annuaire ne tient plus compte des accents
 * (arbitrage Cyril, 23/11). Mesure du jumeau : « fonde » ne trouvait pas
 * « fondé », « Référent » ne trouvait pas « Referent ».
 *
 * Le comportement vit dans `translate()` de PostgreSQL : sous SQLite le test
 * n'a rien a mesurer et se declare saute plutot que vert a vide.
 */
class TASK1893AnnuaireRechercheSansAccentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Recherche sans accents : PostgreSQL seulement (translate()).');
        }

        $this->org = Organization::factory()->create(['slug' => 'org-1893', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);
        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id, 'city' => null, 'first_name' => 'Visiteur', 'name' => 'NEUTRE', 'bio' => null]);
    }

    private function membre(array $surcharge): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id, 'approved_at' => now(), 'bio' => 'Rien a signaler.', 'slogan' => null, 'city' => 'Lyon',
        ], $surcharge));
    }

    private function chercher(string $q): string
    {
        return $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org).'?q='.urlencode($q))
            ->assertOk()->getContent();
    }

    public function test_les_accents_ne_comptent_plus_dans_les_deux_sens(): void
    {
        $this->membre(['first_name' => 'Hélène', 'name' => 'DUPRÉ', 'bio' => "J'ai fondé deux projets.", 'city' => 'Orléans']);
        $this->membre(['first_name' => 'Vincent', 'name' => 'PAILLIEZ', 'bio' => 'Referent technique.', 'city' => 'Menton']);

        // Saisie SANS accent -> donnee AVEC accent.
        foreach (['fonde', 'helene', 'DUPRE', 'orleans', 'helene dupre'] as $q) {
            $html = $this->chercher($q);
            $this->assertStringContainsString('DUPRÉ', $html, "« {$q} » doit trouver Hélène DUPRÉ.");
            $this->assertStringNotContainsString('PAILLIEZ', $html, "« {$q} » ne doit pas trouver Vincent.");
        }

        // Saisie AVEC accent -> donnee SANS accent.
        $html = $this->chercher('Référent');
        $this->assertStringContainsString('PAILLIEZ', $html);
        $this->assertStringNotContainsString('DUPRÉ', $html);
    }

    public function test_une_recherche_sans_rapport_ne_rend_toujours_rien(): void
    {
        $this->membre(['first_name' => 'Hélène', 'name' => 'DUPRÉ']);

        $this->assertStringNotContainsString('DUPRÉ', $this->chercher('zzzz'));
    }
}
