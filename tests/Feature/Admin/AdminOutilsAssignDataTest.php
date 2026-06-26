<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminOutilsAssignDataTest extends TestCase
{
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access ─────────────────────────────────────────────────────────────────

    public function test_guest_cannot_access_assign_data_form(): void
    {
        $this->get(route('admin.outils.assign-data'))->assertRedirect(route('login'));
    }

    public function test_admin_can_access_assign_data_form(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data'))
            ->assertOk();
    }

    // ── Users confirmation guard ───────────────────────────────────────────────

    public function test_assign_refuses_users_without_confirmation(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
            ])
            ->assertSessionHasErrors('confirmation');
    }

    public function test_assign_accepts_users_with_confirmation(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->refresh();

        $this->assertNull($user->organization_id);

        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertEquals($this->org->id, $user->organization_id);
    }

    public function test_assign_refuses_users_with_wrong_confirmation_text(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
                'confirmation' => 'OUI JE CONFIRME',
            ])
            ->assertSessionHasErrors('confirmation');
    }

    // ── Non-critical datasets ──────────────────────────────────────────────────

    public function test_assign_works_for_non_critical_datasets(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => $this->org->id,
                'datasets' => ['services'],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
    }

    public function test_assign_requires_at_least_one_dataset(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => $this->org->id,
                'datasets' => [],
            ])
            ->assertSessionHasErrors('datasets');
    }

    // ── No raw translation keys in dataset labels ────────────────────────────

    public function test_dataset_labels_never_show_raw_keys(): void
    {
        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data', [
                'organization_id' => $this->org->id,
            ]));

        $response->assertDontSee('admin/users.title');
        $response->assertDontSee('admin/services.title');
        $response->assertSee('Utilisateurs');
        $response->assertSee('Services');
    }

    // ── No sensitive columns in detail ────────────────────────────────────────

    public function test_detail_does_not_expose_sensitive_columns(): void
    {
        User::factory()->count(3)->create();

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data.detail', [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
            ]), ['Accept-Language' => 'fr']);

        $response->assertDontSee('password');
        $response->assertDontSee('remember_token');
    }

    // ── Detail view (read-only) ────────────────────────────────────────────────

    public function test_assign_detail_is_read_only(): void
    {
        User::factory()->count(3)->create();

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data.detail', [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
            ]), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('lecture seule');
    }

    public function test_assign_detail_requires_datasets(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data.detail'))
            ->assertSessionHasErrors('datasets');
    }

    // ── Detail filters ─────────────────────────────────────────────────────────

    public function test_detail_filter_without_org_shows_only_unassigned(): void
    {
        $noOrg = User::factory()->create(['name' => 'NO_ORG_USER', 'organization_id' => null]);
        $inOrg = User::factory()->create(['name' => 'IN_ORG_USER', 'organization_id' => $this->org->id]);

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data.detail', [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
                'filter' => 'without_org',
            ]), ['Accept-Language' => 'fr']);

        $response->assertSee('NO_ORG_USER');
        $response->assertDontSee('IN_ORG_USER');
    }

    public function test_detail_filter_in_org_shows_only_selected_org(): void
    {
        $noOrg = User::factory()->create(['name' => 'NO_ORG_USER', 'organization_id' => null]);
        $inOrg = User::factory()->create(['name' => 'IN_ORG_USER', 'organization_id' => $this->org->id]);

        $response = $this->actingAs($this->makeAdmin())
            ->get(route('admin.outils.assign-data.detail', [
                'organization_id' => $this->org->id,
                'datasets' => ['users'],
                'filter' => 'in_org',
            ]), ['Accept-Language' => 'fr']);

        $response->assertDontSee('NO_ORG_USER');
        $response->assertSee('IN_ORG_USER');
    }

    // ── Organization requirement ───────────────────────────────────────────────

    public function test_assign_rejects_invalid_organization_id(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.outils.assign-data.do'), [
                'organization_id' => '00000000-0000-0000-0000-000000000000',
                'datasets' => ['services'],
            ])
            ->assertSessionHasErrors('organization_id');
    }
}
