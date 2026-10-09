<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1672 — pourquoi une assertion de nom doit viser la forme ECHAPPEE,
 * et pourquoi un litteral de test doit etre DISTINCTIF.
 *
 * Le 09/10/2026, la CI de TASK-1671 est passee au rouge sur un test de
 * TASK-1667 sans aucun rapport avec son sujet. Diagnostic : faker avait tire
 * « Julien O'Hara ». Blade echappe l'apostrophe en `&#039;`, donc le nom BRUT
 * n'etait plus dans le HTML.
 *
 * Ces tests ne rejouent pas le hasard : ils FORCENT les deux conditions
 * hostiles et caracterisent le mecanisme, pour que la lecon ne se reperde pas.
 */
class TASK1672DeterministicNameAssertionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Organisation T1672']);
        $this->superAdmin = User::factory()->for($this->organization)->create([
            'first_name' => 'Sacha',
            'name' => 'Vigil',
            'is_admin' => true,
        ]);
    }

    private function corpsDuTableau(string $html): string
    {
        $debut = strpos($html, '<tbody');
        $fin = strpos($html, '</tbody>', $debut ?: 0);

        return $debut === false || $fin === false
            ? $html
            : substr($html, $debut, $fin - $debut);
    }

    private function ecriture(User $pour, string $motif, int $delta = 10): void
    {
        DB::table('point_ledger')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $pour->id,
            'organization_id' => $this->organization->id,
            'delta' => $delta,
            'reason' => $motif,
            'created_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Defaut A — un nom a apostrophe sort ECHAPPE
    // -------------------------------------------------------------------------

    public function test_un_nom_a_apostrophe_sort_echappe_et_le_nom_brut_est_absent(): void
    {
        $membre = User::factory()->for($this->organization)->create([
            'first_name' => 'Julien',
            'name' => "O'Hara",
        ]);
        $this->ecriture($membre, 'T1672-ECRITURE');

        $corps = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', [
                'organization_id' => $this->organization->id,
                'user_id' => $membre->id,
            ]))
            ->assertOk()
            ->getContent());

        $this->assertSame("Julien O'Hara", $membre->full_name);

        // LE MECANISME, nomme : la forme echappee est la, la forme brute NON.
        $this->assertStringContainsString(e($membre->full_name), $corps);
        $this->assertStringNotContainsString($membre->full_name, $corps);
        $this->assertStringContainsString('&#039;', $corps);
    }

    public function test_un_nom_sans_apostrophe_ne_distingue_pas_les_deux_formes(): void
    {
        // Le pendant indispensable : sans caractere a echapper, `e()` est
        // l'identite. C'est precisement pourquoi la suite etait VERTE pendant
        // des semaines — et pourquoi ce vert ne prouvait rien.
        $membre = User::factory()->for($this->organization)->create([
            'first_name' => 'Marie',
            'name' => 'Dupont',
        ]);
        $this->ecriture($membre, 'T1672-ECRITURE');

        $corps = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', [
                'organization_id' => $this->organization->id,
                'user_id' => $membre->id,
            ]))
            ->assertOk()
            ->getContent());

        $this->assertSame($membre->full_name, e($membre->full_name));
        $this->assertStringContainsString($membre->full_name, $corps);
    }

    public function test_une_assertion_negative_sur_un_nom_brut_est_AVEUGLE(): void
    {
        // LE FAUX VERT, et aucun sabotage ne peut le montrer : une assertion
        // negative portant le nom BRUT reussit meme quand la personne EST
        // presente a l'ecran. Elle ne protege donc plus rien.
        $membre = User::factory()->for($this->organization)->create([
            'first_name' => 'Aude',
            'name' => "D'Ambre",
        ]);
        $this->ecriture($membre, 'T1672-ECRITURE');

        $corps = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', [
                'organization_id' => $this->organization->id,
                'user_id' => $membre->id,
            ]))
            ->assertOk()
            ->getContent());

        // La personne est bel et bien la.
        $this->assertStringContainsString(e($membre->full_name), $corps);

        // Et pourtant « elle n'est pas la » passe, si l'on compare au brut.
        $this->assertStringNotContainsString($membre->full_name, $corps);
    }

    // -------------------------------------------------------------------------
    // Defaut B — un litteral commun entre en collision avec un patronyme
    // -------------------------------------------------------------------------

    public function test_un_litteral_commun_entre_en_collision_avec_un_patronyme(): void
    {
        // `Legrand` contient `grand`. Chercher `'grand'` par `strpos` dans la
        // page entiere trouve donc le NOM, pas le motif de l'ecriture — et
        // l'ordre mesure n'est plus celui des ecritures.
        $legrand = User::factory()->for($this->organization)->create([
            'first_name' => 'Marie',
            'name' => 'Legrand',
        ]);
        $this->ecriture($legrand, 'T1672-ECRITURE');

        $page = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('grand', $page);
        $this->assertStringNotContainsString('grand', str_replace('Legrand', '', $page));
    }

    public function test_un_litteral_distinctif_borne_au_tableau_ordonne_vraiment(): void
    {
        // La forme CORRIGEE : un motif qui ne peut pas apparaitre par hasard,
        // cherche dans le corps du tableau seulement.
        $membre = User::factory()->for($this->organization)->create([
            'first_name' => 'Marie',
            'name' => 'Legrand',
        ]);

        DB::table('point_ledger')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $membre->id, 'organization_id' => $this->organization->id, 'delta' => 5, 'reason' => 'T1672-MOUVEMENT-BAS', 'created_at' => now()->subDay()],
            ['id' => (string) Str::uuid(), 'user_id' => $membre->id, 'organization_id' => $this->organization->id, 'delta' => 90, 'reason' => 'T1672-MOUVEMENT-HAUT', 'created_at' => now()],
        ]);

        $asc = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', [
                'organization_id' => $this->organization->id,
                'sort' => 'delta',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->getContent());

        $this->assertLessThan(
            strpos($asc, 'T1672-MOUVEMENT-HAUT'),
            strpos($asc, 'T1672-MOUVEMENT-BAS'),
            'en ordre croissant, le plus petit mouvement vient en premier'
        );
    }
}
