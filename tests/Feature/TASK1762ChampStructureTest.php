<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1762 point 1 — le champ « Structure » du profil.
 *
 * Societe, association, collectif. Pose AVANT prenom et nom sur
 * `/org/{org}/profile/edit`, comme Cyril l'a demande.
 */
class TASK1762ChampStructureTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        // TASK-1762 point 2 — le champ n'existe que si l'Organization l'a voulu.
        // Les tests du point 1 decrivent donc une Organization qui l'a voulu ;
        // le point 2 a sa propre classe pour le cas contraire.
        $this->org = Organization::factory()->create([
            'slug' => 'org-1762', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);

        $this->membre = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille',
            'name' => 'MOREAU',
            'phone' => '0600000000',
        ]);
    }

    /** @return array<string, mixed> le formulaire complet, pour ne tester qu'une variable a la fois */
    private function formulaire(array $surcharge = []): array
    {
        return array_merge([
            'first_name' => $this->membre->first_name,
            'name' => $this->membre->name,
            'email' => $this->membre->email,
            'phone' => $this->membre->phone,
        ], $surcharge);
    }

    /** La colonne existe et elle est NULLABLE : aucune ligne existante n'est touchee. */
    public function test_la_colonne_existe_et_reste_facultative(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'structure'));
        $this->assertNull($this->membre->fresh()->structure);
    }

    /** 🔴 Le champ est sur le formulaire, et AVANT prenom et nom. */
    public function test_le_champ_est_sur_le_formulaire_avant_prenom_et_nom(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-structure-field', $html);
        $this->assertStringContainsString('name="structure"', $html);

        // La POSITION, et non la seule presence : « avant Prenom et Nom » est
        // la demande, et un champ ajoute a la fin passerait un test de presence.
        $positionStructure = strpos($html, 'name="structure"');
        $positionPrenom = strpos($html, 'name="first_name"');
        $positionNom = strpos($html, 'name="name"');

        $this->assertNotFalse($positionPrenom);
        $this->assertNotFalse($positionNom);
        $this->assertLessThan($positionPrenom, $positionStructure, 'La structure doit preceder le prenom.');
        $this->assertLessThan($positionNom, $positionStructure, 'La structure doit preceder le nom.');
    }

    /** On l'enregistre. */
    public function test_la_structure_est_enregistree(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['structure' => 'SCOP Oxalis']))
            ->assertSessionHasNoErrors();

        $this->assertSame('SCOP Oxalis', $this->membre->fresh()->structure);
    }

    /**
     * 🔴 Et un nom de STRUCTURE n'est pas un nom de PERSONNE.
     *
     * `NomDePersonneAcceptable` (T1712) refuse ce qui ne ressemble pas a un nom
     * de personne. L'appliquer ici ecarterait « Association Les Amis du
     * Quartier » — exactement ce que le champ sert a ecrire.
     */
    public function test_un_nom_d_association_est_accepte(): void
    {
        foreach (['Association Les Amis du Quartier', 'SCIC La Fabrique 21', 'Café associatif « Le Coin »'] as $nom) {
            $this->actingAs($this->membre)
                ->put(route('profile.update'), $this->formulaire(['structure' => $nom]))
                ->assertSessionHasNoErrors();

            $this->assertSame($nom, $this->membre->fresh()->structure);
        }
    }

    /** Laisser le champ vide l'efface — on doit pouvoir se retirer d'une structure. */
    public function test_le_champ_peut_etre_vide_a_nouveau(): void
    {
        $this->membre->update(['structure' => 'Ancienne Asso']);

        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['structure' => '']))
            ->assertSessionHasNoErrors();

        // STRICTEMENT `null`, et non « vide » : `ConvertEmptyStringsToNull`
        // convertit la chaine vide, et un `?: null` complaisant accepterait une
        // chaine vide stockee — qui se lirait « structure renseignee » partout
        // ou l'on testera `filled()`.
        $this->assertNull($this->membre->fresh()->structure);
    }

    /** Trop long : refuse, et le reste du profil n'est pas enregistre pour autant. */
    public function test_une_structure_trop_longue_est_refusee(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire([
                'structure' => str_repeat('a', 256),
                'phone' => '0611111111',
            ]))
            ->assertSessionHasErrors('structure');

        $this->assertNull($this->membre->fresh()->structure);
        $this->assertSame('0600000000', $this->membre->fresh()->phone);
    }

    /**
     * 🔴 Un PUT qui ne porte pas le champ n'efface pas la structure.
     *
     * Le formulaire de profil n'est pas le seul chemin vers `update()` — la
     * lecon est deja ecrite dans le controleur pour `profile_links`.
     */
    public function test_un_envoi_sans_le_champ_n_efface_pas_la_structure(): void
    {
        $this->membre->update(['structure' => 'SCOP Oxalis']);

        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire())
            ->assertSessionHasNoErrors();

        $this->assertSame('SCOP Oxalis', $this->membre->fresh()->structure);
    }
}
