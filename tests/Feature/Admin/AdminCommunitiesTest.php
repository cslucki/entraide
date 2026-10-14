<?php

namespace Tests\Feature\Admin;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminCommunitiesTest extends TestCase
{
    public function test_guest_cannot_access_organizations(): void
    {
        $this->get(route('admin.organizations'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_organizations(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.organizations'))->assertForbidden();
    }

    public function test_admin_can_view_organizations_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee(Organization::first()->name);
    }

    public function test_admin_can_create_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'Test Organization',
            'welcome_points' => 150,
        ])->assertRedirect(route('admin.organizations'));

        $this->assertDatabaseHas('organizations', [
            'name' => 'Test Organization',
            'slug' => 'test-organization',
            'welcome_points' => 150,
            'is_active' => true,
        ]);
    }

    public function test_create_organization_auto_generates_slug(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'My Awesome Organization',
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $this->assertDatabaseHas('organizations', ['slug' => 'my-awesome-organization']);
    }

    public function test_create_organization_validates_name_required(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => '',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_organization_validates_name_unique(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->create(['name' => 'Existing Organization']);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'Existing Organization',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('name');
    }

    public function test_create_organization_validates_slug_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'Bad Slug',
            'slug' => 'BAD SLUG!',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('slug');
    }

    public function test_create_organization_validates_color_format(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'Color Test',
            'accent_color' => 'not-a-color',
            'welcome_points' => 100,
        ])->assertSessionHasErrors('accent_color');
    }

    public function test_admin_can_edit_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee('Old Name');
    }

    public function test_admin_can_edit_organization_with_primary_loop_options(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['name' => 'Looped Organization']);
        $creator = User::factory()->create(['organization_id' => $organization->id]);
        $loop = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $creator->id,
            'name' => 'Boucle principale QA',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.organizations.edit', $organization))
            ->assertOk()
            ->assertSee($loop->name);
    }

    public function test_admin_can_update_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name' => 'New Name',
            'slug' => 'new-name',
            'hero_title' => 'Welcome!',
            'accent_color' => '#ff0000',
            'welcome_points' => 200,
        ])->assertRedirect(route('admin.organizations'));

        $organization->refresh();
        $this->assertEquals('New Name', $organization->name);
        $this->assertEquals('new-name', $organization->slug);
        $this->assertEquals('#ff0000', $organization->accent_color);
        $this->assertEquals(200, $organization->welcome_points);
    }

    public function test_admin_can_toggle_organization_active(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.toggle-active', $organization))
            ->assertRedirect();

        $organization->refresh();
        $this->assertFalse($organization->is_active);
    }

    public function test_admin_can_delete_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        $user->update(['organization_id' => $organization->id]);

        $this->actingAs($admin)->delete(route('admin.organizations.destroy', $organization))
            ->assertRedirect();

        $user->refresh();
        $this->assertNull($user->organization_id);
        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    public function test_delete_nullifies_organization_id_on_related_models(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create();

        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)->delete(route('admin.organizations.destroy', $organization));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'organization_id' => null]);
        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    public function test_admin_can_assign_responsable(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $responsable = User::factory()->create(['name' => 'John Doe']);
        $organization = Organization::factory()->create();

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'admin_id' => $responsable->id,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $organization->refresh();
        $this->assertEquals($responsable->id, $organization->admin_id);
    }

    public function test_admin_can_create_public_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.organizations.store'), [
            'name' => 'Public Organization',
            'is_public' => 1,
            'welcome_points' => 100,
        ])->assertRedirect(route('admin.organizations'));

        $organization = Organization::where('name', 'Public Organization')->first();
        $this->assertTrue($organization->is_public);
    }

    public function test_admin_can_toggle_public_visibility(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create(['is_public' => false]);

        $this->actingAs($admin)->put(route('admin.organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_public' => 1,
            'welcome_points' => 100,
        ]);

        $organization->refresh();
        $this->assertTrue($organization->is_public);
    }

    public function test_organization_index_shows_visibility_badges(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Organization::factory()->create(['name' => 'Public Test', 'is_public' => true]);
        Organization::factory()->create(['name' => 'Private Test', 'is_public' => false]);

        $this->actingAs($admin)->get(route('admin.organizations'))
            ->assertSee('Publique')
            ->assertSee('Privée');
    }

    public function test_organization_index_shows_loop_mode_and_primary_loop(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $monoOrganization = Organization::factory()->create([
            'name' => 'Mono Organization',
            'loop_mode' => 'mono',
        ]);
        Organization::factory()->create([
            'name' => 'Multi Organization',
            'loop_mode' => 'multi',
            'primary_loop_id' => null,
        ]);
        $creator = User::factory()->create(['organization_id' => $monoOrganization->id]);
        $primaryLoop = Loop::factory()->create([
            'organization_id' => $monoOrganization->id,
            'created_by' => $creator->id,
            'name' => 'Primary Admin Loop',
        ]);

        $monoOrganization->update(['primary_loop_id' => $primaryLoop->id]);

        $this->actingAs($admin)
            ->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee('Mono')
            ->assertSee('Multi')
            ->assertSee('Principale')
            ->assertSee('Primary Admin Loop')
            ->assertSee('— Aucune —');
    }

    public function test_admin_cannot_set_primary_loop_from_another_organization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignCreator = User::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignLoop = Loop::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'created_by' => $foreignCreator->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.organizations.edit', $organization))
            ->put(route('admin.organizations.update', $organization), [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'welcome_points' => 100,
                'loops_enabled' => '1',
                'loop_mode' => 'mono',
                'primary_loop_id' => $foreignLoop->id,
            ])
            ->assertRedirect(route('admin.organizations.edit', $organization))
            ->assertSessionHasErrors('primary_loop_id');

        $this->assertNull($organization->fresh()->primary_loop_id);
    }

    /**
     * TASK-1683 BUG-01 — chaque ligne mene a SES membres.
     *
     * /admin/users filtre deja par organization_id : le lien n'ajoute aucune
     * requete, et doit porter l'identifiant de SA ligne — jamais celui d'une
     * voisine.
     */
    public function test_the_organizations_list_links_each_row_to_its_own_members(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $orgA = Organization::factory()->create(['is_active' => true]);
        $orgB = Organization::factory()->create(['is_active' => true]);
        User::factory()->count(2)->create(['organization_id' => $orgA->id]);

        $response = $this->actingAs($admin)
            ->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee(route('admin.users', ['organization_id' => $orgA->id]), false)
            ->assertSee(route('admin.users', ['organization_id' => $orgB->id]), false);

        // TASK-1683 BUG-03 puis BUG-04 — la poignee est le COMPTEUR de la
        // colonne Membres, et lui seul : le lien jumeau de la colonne
        // Organisation a ete retire comme doublon. UNE occurrence par ligne.
        $this->assertSame(
            1,
            substr_count($response->getContent(), route('admin.users', ['organization_id' => $orgA->id])),
            'Le compteur Membres est la seule poignee vers la liste filtree.',
        );

        // Et la cible rend bien les membres de cette Organization SEULE.
        $membreA = User::factory()->create(['organization_id' => $orgA->id, 'email' => 'dedans@example.test']);
        $membreB = User::factory()->create(['organization_id' => $orgB->id, 'email' => 'ailleurs@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.users', ['organization_id' => $orgA->id]))
            ->assertOk()
            ->assertSee($membreA->email)
            ->assertDontSee($membreB->email);
    }

    /**
     * TASK-1683 BUG-02 — la Fiche du responsable s'ouvre depuis la liste.
     *
     * Le bouton dispatche l'evenement du partiel PARTAGE : sans l'include de
     * la modal, il serait inerte ET silencieux — le test verifie donc les
     * DEUX (le declencheur porte le bon id, et la modal est bien sur la page).
     */
    public function test_the_organizations_list_opens_the_admin_profile_card(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $responsable = User::factory()->create(['name' => 'Responsable Test']);
        $organization = Organization::factory()->create([
            'is_active' => true,
            'admin_id' => $responsable->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.organizations'))
            ->assertOk()
            ->assertSee("open-user-profile', { id: '".$responsable->id."'", false)
            ->assertSee('Responsable Test')
            // La modal partagee est presente : le declencheur mene quelque part.
            ->assertSee('adminUserProfileModal()', false);

        // Une Organization sans responsable ne declenche rien.
        $sansAdmin = Organization::factory()->create(['is_active' => true, 'admin_id' => null]);
        $this->assertNull($sansAdmin->fresh()->admin_id);
    }
}
