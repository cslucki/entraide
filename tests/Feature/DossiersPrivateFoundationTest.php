<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DossiersPrivateFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $userA;

    private User $otherUserA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'name' => 'Organisation A',
            'slug' => 'org-a',
            'is_active' => true,
        ]);
        $this->orgB = Organization::factory()->create([
            'name' => 'Organisation B',
            'slug' => 'org-b',
            'is_active' => true,
        ]);

        $this->userA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->otherUserA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['organization_id' => $this->orgB->id]);
    }

    public function test_guest_is_redirected_from_dossiers(): void
    {
        $this->get(route('organization.dossiers.index', $this->orgA))
            ->assertRedirect(route('login'));
    }

    public function test_member_sees_only_own_private_dossiers_in_current_organization(): void
    {
        $own = $this->dossier($this->orgA, $this->userA, 'Mon dossier privé');
        $this->dossier($this->orgA, $this->otherUserA, 'Dossier autre membre');
        $this->dossier($this->orgB, $this->userB, 'Dossier autre organisation');

        $this->actingAs($this->userA)
            ->get(route('organization.dossiers.index', $this->orgA))
            ->assertOk()
            ->assertSee($own->name)
            ->assertDontSee('Dossier autre membre')
            ->assertDontSee('Dossier autre organisation');
    }

    public function test_organization_a_dossiers_are_invisible_from_organization_b(): void
    {
        $this->dossier($this->orgA, $this->userA, 'Dossier Org A');
        $orgBDossier = $this->dossier($this->orgB, $this->userB, 'Dossier Org B');

        $this->actingAs($this->userB)
            ->get(route('organization.dossiers.index', $this->orgB))
            ->assertOk()
            ->assertSee($orgBDossier->name)
            ->assertDontSee('Dossier Org A');
    }

    public function test_user_cannot_see_another_users_private_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->otherUserA, 'Privé autre membre');

        $this->actingAs($this->userA)
            ->get(route('organization.dossiers.edit', ['organization' => $this->orgA, 'dossier' => $dossier->getKey()]))
            ->assertForbidden();
    }

    public function test_creation_uses_current_organization_and_authenticated_owner(): void
    {
        $this->actingAs($this->userA)
            ->post(route('organization.dossiers.store', $this->orgA), [
                'name' => 'Nouveau dossier',
            ])
            ->assertRedirect(route('organization.dossiers.index', $this->orgA));

        $this->assertDatabaseHas('dossiers', [
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->userA->id,
            'name' => 'Nouveau dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    public function test_cross_organization_owner_input_is_rejected(): void
    {
        $this->actingAs($this->userA)
            ->post(route('organization.dossiers.store', $this->orgA), [
                'name' => 'Tentative invalide',
                'owner_id' => $this->userB->id,
            ])
            ->assertSessionHasErrors('owner_id');

        $this->assertDatabaseMissing('dossiers', [
            'name' => 'Tentative invalide',
        ]);
    }

    public function test_owner_can_rename_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->userA, 'Ancien nom');

        $this->actingAs($this->userA)
            ->patch(route('organization.dossiers.update', ['organization' => $this->orgA, 'dossier' => $dossier->getKey()]), [
                'name' => 'Nouveau nom',
            ])
            ->assertRedirect(route('organization.dossiers.index', $this->orgA));

        $this->assertDatabaseHas('dossiers', [
            'id' => $dossier->id,
            'name' => 'Nouveau nom',
        ]);
    }

    public function test_non_owner_cannot_rename_dossier(): void
    {
        $dossier = $this->dossier($this->orgA, $this->userA, 'Dossier propriétaire');

        $this->actingAs($this->otherUserA)
            ->patch(route('organization.dossiers.update', ['organization' => $this->orgA, 'dossier' => $dossier->getKey()]), [
                'name' => 'Modification interdite',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('dossiers', [
            'id' => $dossier->id,
            'name' => 'Dossier propriétaire',
        ]);
    }

    public function test_delete_is_logical_and_deleted_dossier_is_absent_from_list(): void
    {
        $dossier = $this->dossier($this->orgA, $this->userA, 'Dossier à supprimer');

        $this->actingAs($this->userA)
            ->delete(route('organization.dossiers.destroy', ['organization' => $this->orgA, 'dossier' => $dossier->getKey()]))
            ->assertRedirect(route('organization.dossiers.index', $this->orgA));

        $this->assertSoftDeleted('dossiers', ['id' => $dossier->id]);

        $this->actingAs($this->userA)
            ->get(route('organization.dossiers.index', $this->orgA))
            ->assertOk()
            ->assertDontSee('Dossier à supprimer');
    }

    public function test_cross_tenant_route_model_is_not_exposed(): void
    {
        $dossier = $this->dossier($this->orgA, $this->userA, 'Dossier Org A');

        $this->actingAs($this->userB)
            ->get(route('organization.dossiers.edit', ['organization' => $this->orgB, 'dossier' => $dossier->getKey()]))
            ->assertNotFound();
    }

    public function test_root_dossiers_route_is_absent(): void
    {
        $this->assertFalse(Route::has('dossiers.index'));

        $this->actingAs($this->userA)
            ->get('/dossiers')
            ->assertNotFound();
    }

    public function test_dossiers_do_not_use_loop_or_community_columns_or_relations(): void
    {
        $this->assertFalse(Schema::hasColumn('dossiers', 'loop_id'));
        $this->assertFalse(Schema::hasColumn('dossiers', 'community_id'));
        $this->assertFalse(method_exists(Dossier::class, 'loops'));
        $this->assertFalse(method_exists(Dossier::class, 'loop'));
    }

    private function dossier(Organization $organization, User $owner, string $name): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }
}
