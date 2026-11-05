<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 7 — le SuperAdmin modifie la structure d'un membre.
 *
 * ## La particularite de cet ecran
 *
 * Le SuperAdmin edite des comptes de TOUTES les Organizations. Le drapeau qui
 * gouverne le champ est donc celui de la personne EDITEE — jamais celui de
 * l'URL, ni celui de l'Organization du SuperAdmin lui-meme.
 */
class TASK1762StructureSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgAvec;

    private Organization $orgSans;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // L'Organization du SuperAdmin n'autorise PAS la structure : si une
        // surface lisait son drapeau a lui, les tests ci-dessous rougiraient.
        $this->orgSans = Organization::factory()->create([
            'slug' => 'org-1762-sans', 'is_active' => true, 'structure_enabled' => false,
        ]);
        $this->orgAvec = Organization::factory()->create([
            'slug' => 'org-1762-avec', 'is_active' => true, 'structure_enabled' => true,
        ]);

        app()->instance('current_organization', $this->orgSans);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->orgSans->id,
        ]);
    }

    private function membre(Organization $org, array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU',
            'phone' => '0600000000',
        ], $surcharge));
    }

    private function formulaire(User $u, array $surcharge = []): array
    {
        return array_merge([
            'first_name' => $u->first_name,
            'name' => $u->name,
            'email' => $u->email,
            // TASK-1762 point 17 — le slogan est desormais OBLIGATOIRE, y
            // compris sur cet ecran.
            'slogan' => 'Je resous des problemes, pas des tickets.',
        ], $surcharge);
    }

    // ── L'affichage ──────────────────────────────────────────────────────────

    /**
     * 🔴 Le champ suit l'Organization de la personne EDITEE.
     *
     * Le SuperAdmin appartient a une Organization qui n'autorise PAS la
     * structure. Si l'ecran lisait son drapeau a lui, ou celui de l'URL, ce
     * test rougirait.
     */
    public function test_le_champ_suit_l_organization_de_la_personne_editee(): void
    {
        $membre = $this->membre($this->orgAvec);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $membre))->assertOk()->getContent();

        $this->assertStringContainsString('data-admin-structure-field', $html);
        $this->assertStringContainsString('name="structure"', $html);
    }

    /** 🔴 Et il disparait pour un compte dont l'Organization ne l'a pas ouvert. */
    public function test_aucun_champ_pour_une_organization_qui_ne_l_a_pas_ouvert(): void
    {
        $membre = $this->membre($this->orgSans);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $membre))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-admin-structure-field', $html);
        $this->assertStringNotContainsString('name="structure"', $html);
    }

    /** La valeur deja enregistree est proposee a la modification. */
    public function test_la_valeur_existante_est_proposee(): void
    {
        $membre = $this->membre($this->orgAvec, ['structure' => 'SCOP Oxalis']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.edit', $membre))->assertOk()->getContent();

        $this->assertStringContainsString('value="SCOP Oxalis"', $html);
    }

    // ── L'ecriture ───────────────────────────────────────────────────────────

    /** Le SuperAdmin modifie la structure. */
    public function test_le_superadmin_modifie_la_structure(): void
    {
        $membre = $this->membre($this->orgAvec, ['structure' => 'Ancienne Asso']);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $membre), $this->formulaire($membre, ['structure' => 'SCIC La Fabrique']))
            ->assertSessionHasNoErrors();

        $this->assertSame('SCIC La Fabrique', $membre->fresh()->structure);
    }

    /** Et il peut l'effacer. */
    public function test_le_superadmin_peut_l_effacer(): void
    {
        $membre = $this->membre($this->orgAvec, ['structure' => 'SCOP Oxalis']);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $membre), $this->formulaire($membre, ['structure' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($membre->fresh()->structure);
    }

    /**
     * 🔴 Un `PUT` FORGE n'ecrit rien sur une Organization qui n'a pas ouvert
     * le champ.
     *
     * C'est la garde qui compte : la vue ne protege rien, elle ne fait que ne
     * pas proposer.
     */
    public function test_un_put_forge_n_ecrit_pas_sur_une_organization_fermee(): void
    {
        $membre = $this->membre($this->orgSans);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $membre), $this->formulaire($membre, ['structure' => 'SARL Forgee']))
            ->assertSessionHasNoErrors();

        $this->assertNull($membre->fresh()->structure);
    }

    /** Trop long : refuse, et rien d'autre n'est enregistre au passage. */
    public function test_une_structure_trop_longue_est_refusee(): void
    {
        $membre = $this->membre($this->orgAvec, ['structure' => 'SCOP Oxalis']);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $membre), $this->formulaire($membre, [
                'structure' => str_repeat('a', 256),
                'first_name' => 'Modifie',
            ]))
            ->assertSessionHasErrors('structure');

        $this->assertSame('SCOP Oxalis', $membre->fresh()->structure);
        $this->assertSame('Camille', $membre->fresh()->first_name);
    }

    /** 🔴 Un membre ordinaire n'approche pas de cet ecran. */
    public function test_un_membre_ordinaire_est_refuse(): void
    {
        $membre = $this->membre($this->orgAvec);
        $quidam = User::factory()->create(['organization_id' => $this->orgAvec->id]);

        $this->actingAs($quidam)->get(route('admin.users.edit', $membre))->assertForbidden();
        $this->actingAs($quidam)
            ->put(route('admin.users.update', $membre), $this->formulaire($membre, ['structure' => 'Forgee']))
            ->assertForbidden();

        $this->assertNull($membre->fresh()->structure);
    }
}
