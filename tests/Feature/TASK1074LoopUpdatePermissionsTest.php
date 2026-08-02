<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TASK1074LoopUpdatePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'loops_enabled' => true,
        ], $overrides));
    }

    private function loop(Organization $organization, array $overrides = []): Loop
    {
        return Loop::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $overrides));
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    // ── Policy unit tests ──────────────────────────────────────────────────

    public function test_owner_is_authorized_to_update(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $loop));
    }

    public function test_organization_admin_is_authorized_to_update_even_without_membership(): void
    {
        $orgAdmin = User::factory()->create();
        $organization = $this->org(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $organization->id]);
        $loop = $this->loop($organization);

        $this->assertTrue(Gate::forUser($orgAdmin->fresh())->allows('update', $loop));
    }

    public function test_standard_member_is_refused(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);

        $this->assertFalse(Gate::forUser($member)->allows('update', $loop));
    }

    public function test_moderator_is_refused(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $moderator = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->moderator()->create(['loop_id' => $loop->id, 'user_id' => $moderator->id]);

        $this->assertFalse(Gate::forUser($moderator)->allows('update', $loop));
    }

    public function test_user_from_another_organization_is_refused(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $otherOrg = $this->org();
        $outsider = User::factory()->create(['organization_id' => $otherOrg->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $outsider->id]);

        $this->assertFalse(Gate::forUser($outsider)->allows('update', $loop));
    }

    public function test_deactivated_owner_is_refused(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'banned_at' => now()]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        $this->assertFalse(Gate::forUser($owner)->allows('update', $loop));
    }

    public function test_owner_is_refused_when_loops_enabled_is_off(): void
    {
        $organization = $this->org(['loops_enabled' => false]);
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        $this->assertFalse(Gate::forUser($owner)->allows('update', $loop));
    }

    public function test_former_owner_no_longer_active_is_refused(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id,
            'user_id' => $owner->id,
            'status' => 'invited',
        ]);

        $this->assertFalse(Gate::forUser($owner)->allows('update', $loop));
    }

    // ── HTTP: edit form + update ─────────────────────────────────────────────

    public function test_owner_can_view_edit_form(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization, ['name' => 'Boucle originale']);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->get(route('loops.edit', $loop))
            ->assertOk()
            ->assertSee('Boucle originale');
    }

    public function test_owner_can_update_name_and_description(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization, ['name' => 'Ancien nom', 'description' => 'Ancienne description']);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), [
                'name' => 'Nouveau nom',
                'description' => 'Nouvelle description',
            ])
            ->assertRedirect(route('loops.show', $loop))
            ->assertSessionHas('success');

        $loop->refresh();
        $this->assertSame('Nouveau nom', $loop->name);
        $this->assertSame('Nouvelle description', $loop->description);
    }

    public function test_slug_is_not_recalculated_on_update(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization, ['name' => 'Nom original', 'slug' => 'nom-original']);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), ['name' => 'Nom complètement différent'])
            ->assertRedirect();

        $this->assertSame('nom-original', $loop->fresh()->slug);
    }

    public function test_organization_admin_can_update_loop_they_do_not_belong_to_as_member(): void
    {
        $orgAdmin = User::factory()->create();
        $organization = $this->org(['admin_id' => $orgAdmin->id]);
        $orgAdmin->update(['organization_id' => $organization->id]);
        $loop = $this->loop($organization, ['name' => 'Boucle gérée']);
        $this->withCurrentOrganization($organization);

        $this->actingAs($orgAdmin->fresh())
            ->put(route('loops.update', $loop), ['name' => 'Renommée par admin org'])
            ->assertRedirect();

        $this->assertSame('Renommée par admin org', $loop->fresh()->name);
    }

    public function test_standard_member_cannot_view_edit_form(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->get(route('loops.edit', $loop))
            ->assertForbidden();
    }

    public function test_standard_member_cannot_submit_update(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization, ['name' => 'Nom protégé']);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->put(route('loops.update', $loop), ['name' => 'Contournement'])
            ->assertForbidden();

        $this->assertSame('Nom protégé', $loop->fresh()->name);
    }

    public function test_user_from_another_organization_gets_404_on_edit(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $otherOrg = $this->org();
        $outsider = User::factory()->create(['organization_id' => $otherOrg->id]);
        $this->withCurrentOrganization($otherOrg);

        $this->actingAs($outsider)
            ->get(route('loops.edit', $loop))
            ->assertNotFound();
    }

    public function test_guest_cannot_access_edit_form(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $this->withCurrentOrganization($organization);

        $this->get(route('loops.edit', $loop))
            ->assertRedirect(route('login'));
    }

    public function test_forged_request_cannot_alter_protected_fields(): void
    {
        $organization = $this->org();
        $otherOrg = $this->org();
        $loop = $this->loop($organization, ['type' => 'custom', 'status' => 'active']);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), [
                'name' => 'Nom légitime',
                'organization_id' => $otherOrg->id,
                'created_by' => $owner->id,
                'type' => 'system',
                'status' => 'archived',
                'preset_key' => 'learning.v1',
            ])
            ->assertRedirect();

        $loop->refresh();
        $this->assertSame($organization->id, $loop->organization_id);
        $this->assertSame('custom', $loop->type);
        $this->assertSame('active', $loop->status);
    }

    public function test_update_requires_name(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->put(route('loops.update', $loop), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    // ── UI: "Modifier" button visibility ──────────────────────────────────────

    public function test_edit_button_is_visible_to_owner_on_show_page(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization);
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($owner)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee(__('loops.edit'));
    }

    public function test_edit_button_is_hidden_from_standard_member_on_show_page(): void
    {
        $organization = $this->org();
        $loop = $this->loop($organization, ['visibility' => 'public']);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id]);
        $this->withCurrentOrganization($organization);

        $this->actingAs($member)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertDontSee(__('loops.edit'));
    }
}
