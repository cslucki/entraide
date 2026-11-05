<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1762 point 2 — « On peut renseigner sa structure », par Organization.
 *
 * Decoche par defaut. C'est le SuperAdmin qui ouvre le champ, Organization par
 * Organization.
 *
 * ## Ce que ce fichier garde vraiment
 *
 * Masquer un champ ne garde RIEN. Le test qui compte ici est celui du `PUT`
 * forge : quand l'Organization n'a pas coche la case, une requete qui porte
 * `structure` ne doit rien ecrire — meme si le champ n'etait nulle part a
 * l'ecran pour la composer.
 */
class TASK1762DrapeauStructureTest extends TestCase
{
    use RefreshDatabase;

    private function organization(bool $active): Organization
    {
        $org = Organization::factory()->create([
            'slug' => 'org-1762-'.($active ? 'oui' : 'non'),
            'is_active' => true,
            'locale' => 'fr',
            'structure_enabled' => $active,
        ]);

        app()->instance('current_organization', $org);

        return $org;
    }

    private function membre(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'first_name' => 'Camille',
            'name' => 'MOREAU',
            'phone' => '0600000000',
        ]);
    }

    private function formulaire(User $u, array $surcharge = []): array
    {
        return array_merge([
            'first_name' => $u->first_name,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
        ], $surcharge);
    }

    // ── Le defaut ────────────────────────────────────────────────────────────

    /** 🔴 DECOCHE par defaut — demande explicite de Cyril. */
    public function test_le_drapeau_est_decoche_a_la_fabrique(): void
    {
        $this->assertTrue(Schema::hasColumn('organizations', 'structure_enabled'));

        // Sans rien preciser : une Organization nait avec la case decochee.
        $org = Organization::factory()->create(['slug' => 'org-1762-neuve', 'is_active' => true]);

        $this->assertFalse((bool) $org->fresh()->structure_enabled);
    }

    // ── L'affichage ──────────────────────────────────────────────────────────

    /** Case cochee : le champ est la. */
    public function test_le_champ_apparait_quand_l_organization_l_a_voulu(): void
    {
        $org = $this->organization(true);
        $membre = $this->membre($org);

        $html = $this->actingAs($membre)->get(route('organization.profile.edit', $org))->assertOk()->getContent();

        $this->assertStringContainsString('data-profile-structure-field', $html);
    }

    /** 🔴 Case decochee : le champ n'est NULLE PART. */
    public function test_le_champ_disparait_quand_l_organization_ne_l_a_pas_voulu(): void
    {
        $org = $this->organization(false);
        $membre = $this->membre($org);

        $html = $this->actingAs($membre)->get(route('organization.profile.edit', $org))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-profile-structure-field', $html);
        $this->assertStringNotContainsString('name="structure"', $html);
    }

    // ── LA GARDE QUI COMPTE ──────────────────────────────────────────────────

    /**
     * 🔴 Un `PUT` FORGE n'ecrit rien quand la case est decochee.
     *
     * C'est le seul test de ce fichier qui garde quelque chose : la vue ne
     * protege rien, elle ne fait que ne pas proposer. Si la regle restait dans
     * le tableau de base, la cle entrerait dans `$data` et serait enregistree
     * par une requete composee a la main.
     */
    public function test_un_put_forge_n_ecrit_pas_la_structure(): void
    {
        $org = $this->organization(false);
        $membre = $this->membre($org);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['structure' => 'SARL Forgee']))
            ->assertSessionHasNoErrors();

        $this->assertNull($membre->fresh()->structure, 'Une requete forgee a ecrit une structure sur une Organization qui ne l\'autorise pas.');
    }

    /**
     * Et une structure DEJA ecrite n'est pas effacee quand on decoche.
     *
     * Decocher la case retire le champ, ce n'est pas une suppression de donnees :
     * recocher doit rendre a chacun ce qu'il avait saisi.
     */
    public function test_decocher_ne_detruit_pas_ce_qui_etait_deja_saisi(): void
    {
        $org = $this->organization(true);
        $membre = $this->membre($org);

        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['structure' => 'SCOP Oxalis']))
            ->assertSessionHasNoErrors();

        $org->update(['structure_enabled' => false]);

        // Une modification ordinaire du profil, le champ n'etant plus affiche.
        $this->actingAs($membre)
            ->put(route('profile.update'), $this->formulaire($membre, ['phone' => '0611111111']))
            ->assertSessionHasNoErrors();

        $this->assertSame('SCOP Oxalis', $membre->fresh()->structure);
    }

    // ── L'ecran du SuperAdmin ────────────────────────────────────────────────

    /** La case est sur l'edition d'Organization, et elle reflete l'etat. */
    public function test_la_case_est_sur_l_ecran_d_edition(): void
    {
        $org = $this->organization(false);
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $org->id]);

        $html = $this->actingAs($superAdmin)
            ->get(route('admin.organizations.edit', $org))->assertOk()->getContent();

        $this->assertStringContainsString('data-admin-org-structure-enabled', $html);
        $this->assertStringContainsString(__('admin.org_structure_enabled_label'), $html);
    }

    /** 🔴 Le SuperAdmin coche, et cela s'enregistre. */
    public function test_le_superadmin_peut_cocher_puis_decocher(): void
    {
        $org = $this->organization(false);
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $org->id]);

        // Le formulaire d'Organization porte d'autres champs obligatoires
        // (`welcome_points`) : n'envoyer que les trois qui nous interessent
        // mesurerait la validation du formulaire, pas le drapeau.
        $commun = ['name' => $org->name, 'slug' => $org->slug, 'welcome_points' => $org->welcome_points ?? 0];

        $this->actingAs($superAdmin)
            ->put(route('admin.organizations.update', $org), $commun + ['structure_enabled' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $org->fresh()->structure_enabled);

        // Et le chemin inverse : une case decochee n'est pas envoyee par le
        // navigateur, c'est le champ cache `value="0"` qui la porte.
        $this->actingAs($superAdmin)
            ->put(route('admin.organizations.update', $org), $commun + ['structure_enabled' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $org->fresh()->structure_enabled);
    }
}
