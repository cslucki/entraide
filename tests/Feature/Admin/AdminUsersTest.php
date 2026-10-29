<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_users(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_users(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.users'))->assertStatus(403);
    }

    public function test_admin_can_view_users_list(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->count(3)->create();

        $this->actingAs($admin)->get(route('admin.users'))->assertOk();
    }

    // ── Ban / Unban ───────────────────────────────────────────────────────────

    public function test_admin_can_ban_a_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['banned_at' => null]);

        $this->actingAs($admin)
            ->patch(route('admin.users.ban', $user))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_admin_cannot_ban_themselves(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.ban', $admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($admin->fresh()->banned_at);
    }

    public function test_admin_can_unban_a_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($admin)
            ->patch(route('admin.users.unban', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh()->banned_at);
    }

    // ── Points adjustment ─────────────────────────────────────────────────────

    public function test_admin_can_add_points_to_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 50])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(150, $user->fresh()->points_balance);
    }

    public function test_admin_can_remove_points_from_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => -30])
            ->assertRedirect();

        $this->assertSame(70, $user->fresh()->points_balance);
    }

    public function test_adjust_points_writes_to_point_ledger(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 25]);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta' => 25,
            'reason' => 'adjustment',
        ]);
    }

    public function test_adjust_points_rejects_zero_delta(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['points_balance' => 100]);

        $this->actingAs($admin)
            ->post(route('admin.users.adjust-points', $user), ['delta' => 0])
            ->assertSessionHasErrors('delta');

        $this->assertSame(100, $user->fresh()->points_balance);
        $this->assertDatabaseMissing('point_ledger', ['user_id' => $user->id, 'reason' => 'adjustment']);
    }

    // ── Toggle admin ──────────────────────────────────────────────────────────

    public function test_admin_can_toggle_admin_rights_of_another_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-admin', $user))
            ->assertRedirect();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_admin_cannot_toggle_their_own_admin_rights(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-admin', $admin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    // ── Toggle availability ───────────────────────────────────────────────────

    public function test_admin_can_toggle_user_availability(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['is_available' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-availability', $user))
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_available);
    }

    public function test_admin_users_list_labels_availability_without_deactivation_wording(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create(['is_available' => true, 'banned_at' => null]);
        User::factory()->create(['is_available' => false, 'banned_at' => null]);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee(__('admin.mark_unavailable'))
            ->assertSee(__('admin.mark_available'));
    }

    // ── Organization immuable (TASK-1726) ────────────────────────────────────

    /**
     * Les QUATRE tests qui vivaient ici mesuraient
     * `PATCH /admin/users/{user}/assign-organization` : affecter un compte,
     * le renvoyer a l'Organization par defaut en postant un champ vide,
     * s'interdire de s'affecter soi-meme, refuser un uuid inconnu.
     *
     * Ils sont remplaces par celui-ci, et non simplement supprimes : une
     * capacite retiree doit laisser une trace de son retrait a l'endroit meme
     * ou elle etait prouvee, sinon la prochaine relecture conclut a un oubli.
     *
     * Le reste de la preuve T1726 — refus par le modele, conservation a
     * l'edition, exceptions legitimes — vit dans
     * `tests/Feature/TASK1726OrganizationImmuableTest.php`.
     */
    public function test_la_route_d_affectation_d_organization_n_existe_plus(): void
    {
        $this->assertFalse(
            Route::has('admin.users.assign-organization'),
            "La route d'affectation doit avoir disparu, pas seulement son bouton."
        );

        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        // L'URL en dur, parce que `route()` ne sait plus la construire. C'est
        // le serveur qu'on mesure ici, pas le helper.
        $this->actingAs($admin)
            ->patch('/admin/users/'.$user->id.'/assign-organization', ['organization_id' => $organization->id])
            ->assertNotFound();

        $this->assertSame($organization->id, $user->fresh()->organization_id);
    }

    public function test_admin_users_list_shows_community_column(): void
    {
        $admin = $this->makeAdmin();
        $organization = Organization::factory()->create(['is_active' => true, 'name' => 'Test Communauté']);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($admin)
            ->get(route('admin.users'))
            ->assertSee('Test Communauté')
            ->assertSee('Communauté');
    }

    // ── Login as user ─────────────────────────────────────────────────────────

    public function test_admin_can_login_as_another_user(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['is_admin' => false, 'banned_at' => null]);

        $this->actingAs($admin)
            ->post(route('admin.users.login-as', $user))
            ->assertRedirect('/');

        $this->assertEquals(auth()->id(), $user->id);
        $this->assertEquals(session('admin_original_id'), $admin->id);
    }

    public function test_guest_cannot_login_as_user(): void
    {
        $user = User::factory()->create();

        $this->post(route('admin.users.login-as', $user))
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_login_as_user(): void
    {
        $regular = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($regular)
            ->post(route('admin.users.login-as', $target))
            ->assertStatus(403);
    }

    public function test_admin_cannot_login_as_banned_user(): void
    {
        $admin = $this->makeAdmin();
        $banned = User::factory()->create(['banned_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.users.login-as', $banned))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(auth()->id(), $admin->id);
    }

    public function test_admin_cannot_login_as_another_admin(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.users.login-as', $otherAdmin))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(auth()->id(), $admin->id);
    }

    public function test_admin_can_switch_back_to_admin_after_login_as(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['is_admin' => false, 'banned_at' => null]);

        // Login as user
        $this->actingAs($admin)
            ->post(route('admin.users.login-as', $user));
        $this->assertEquals(auth()->id(), $user->id);

        // Switch back
        $this->get(route('admin.back-to-admin'))
            ->assertRedirect(route('admin.users'));

        $this->assertEquals(auth()->id(), $admin->id);
        $this->assertNull(session('admin_original_id'));
    }

    public function test_back_to_admin_requires_existing_session(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.back-to-admin'))
            ->assertRedirect('/')
            ->assertSessionHas('error');
    }
}
