<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminUserDeletePreviewTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_delete_preview(): void
    {
        $user = User::factory()->create();
        $this->get(route('admin.users.delete-preview', $user))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_delete_preview(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('admin.users.delete-preview', $user))->assertStatus(403);
    }

    public function test_admin_can_access_delete_preview(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();
        $this->actingAs($admin)
            ->get(route('admin.users.delete-preview', $user))
            ->assertOk();
    }

    // ── Preview content ───────────────────────────────────────────────────────

    public function test_delete_preview_shows_user_name(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Alice']);

        $this->actingAs($admin)
            ->get(route('admin.users.delete-preview', $user))
            ->assertOk()
            ->assertSee('Alice');
    }

    public function test_delete_preview_shows_count_tables(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.delete-preview', $user))
            ->assertOk()
            ->assertSee('lignes impactées')
            ->assertSee('Données liées');
    }

    // ── POST dry-run ──────────────────────────────────────────────────────────

    public function test_post_delete_with_wrong_name_returns_preview(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Bob']);

        $this->actingAs($admin)
            ->post(route('admin.users.delete', $user), [
                'confirmation' => 'WrongName',
            ])
            ->assertOk()
            ->assertSee('Bob')
            ->assertDontSee('aperçu dry-run');
    }

    public function test_post_delete_with_correct_name_shows_dry_run_message(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Charlie']);

        $this->actingAs($admin)
            ->post(route('admin.users.delete', $user), [
                'confirmation' => 'Charlie',
            ])
            ->assertOk()
            ->assertSee('aperçu')
            ->assertSee('Dry-run');
    }

    // ── Org-admin ─────────────────────────────────────────────────────────────

    public function test_org_admin_cannot_access_global_delete_preview(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_admin' => false, 'organization_id' => $org->id]);
        $org->admin_id = $admin->id;
        $org->save();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.users.delete-preview', $user))
            ->assertStatus(403);
    }

    public function test_org_admin_can_access_org_delete_preview(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_admin' => false, 'organization_id' => $org->id]);
        $org->admin_id = $admin->id;
        $org->save();
        $user = User::factory()->create(['name' => 'Dave', 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('organization.admin.users.delete-preview', [$org->slug, $user]))
            ->assertOk()
            ->assertSee('Dave')
            ->assertSee('lignes impactées');
    }

    public function test_org_admin_cannot_access_other_org_delete_preview(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_admin' => false, 'organization_id' => $org->id]);
        $org->admin_id = $admin->id;
        $org->save();
        $orgB = Organization::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $this->actingAs($admin)
            ->get(route('organization.admin.users.delete-preview', [$org->slug, $userB]))
            ->assertStatus(404);
    }
}
