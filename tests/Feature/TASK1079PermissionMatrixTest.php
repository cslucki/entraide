<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopPermissionSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two permission matrices.
 *
 * Beyond access control, two properties are defended here: nothing is written
 * for a cell left inherited, and neither screen can ever reach an individual
 * Loop.
 */
class TASK1079PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['is_admin' => true]);
        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);
        $this->superAdmin->update(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function settings(): LoopPermissionSettingsService
    {
        return app(LoopPermissionSettingsService::class);
    }

    private function orgUrl(string $suffix = ''): string
    {
        return '/org/'.$this->org->slug.'/admin/loop-permissions'.$suffix;
    }

    // ── Matrice globale : accès ─────────────────────────────────────────────

    public function test_the_super_admin_reaches_the_global_matrix(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.loop-permissions'))->assertOk();

        foreach (LoopRoleRegistry::CANONICAL as $role) {
            $response->assertSee(__('loops.members_role_'.$role));
        }
        $response->assertSee(__('loops.permissions_intro_global'));
    }

    public function test_every_type_is_offered_on_the_global_matrix(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.loop-permissions'))->assertOk();

        foreach (config('loop_types.types') as $definition) {
            $response->assertSee(__($definition['label_key']));
        }
    }

    public function test_an_organization_admin_cannot_reach_the_global_matrix(): void
    {
        $this->actingAs($this->orgAdmin->fresh())->get(route('admin.loop-permissions'))->assertForbidden();
    }

    public function test_a_standard_user_cannot_reach_the_global_matrix(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)->get(route('admin.loop-permissions'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.loop-permissions.update'), ['type' => 'general'])->assertForbidden();
    }

    // ── Matrice globale : écriture ──────────────────────────────────────────

    public function test_saving_an_explicit_value_writes_one_row(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ])->assertRedirect();

        $this->assertSame(1, LoopPermissionSetting::count());
        $this->assertTrue($this->settings()->globalOverride('general', 'facilitator', 'manifesto.publish'));
    }

    public function test_a_cell_left_inherited_writes_nothing(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => [
                'manifesto.publish' => ['facilitator' => 'inherited', 'owner' => 'inherited', 'member' => 'inherited'],
                'manifesto.update' => ['facilitator' => 'inherited'],
            ],
        ])->assertRedirect();

        // Sparse by construction: posting the whole matrix must not materialise it.
        $this->assertSame(0, LoopPermissionSetting::count());
    }

    public function test_returning_to_inherited_deletes_the_row(): void
    {
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);

        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'inherited']],
        ])->assertRedirect();

        $this->assertSame(0, LoopPermissionSetting::count());
    }

    public function test_an_explicit_denial_is_distinct_from_inheritance(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => ['manifesto.update' => ['facilitator' => 'denied']],
        ])->assertRedirect();

        // Denied, not absent — that difference is the whole reason the cell has
        // three states rather than a checkbox.
        $this->assertFalse($this->settings()->globalOverride('general', 'facilitator', 'manifesto.update'));
        $this->assertSame(1, LoopPermissionSetting::count());
    }

    public function test_a_locked_permission_cannot_be_written_through_the_form(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => ['loops.manage_owners' => ['facilitator' => 'allowed']],
        ])->assertRedirect();

        $this->assertSame(0, LoopPermissionSetting::count());
    }

    public function test_an_unknown_key_posted_is_ignored(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => [
                'loops.summon_dragon' => ['facilitator' => 'allowed'],
                'manifesto.update' => ['wizard' => 'allowed'],
            ],
        ])->assertRedirect();

        $this->assertSame(0, LoopPermissionSetting::count());
    }

    public function test_an_unknown_type_falls_back_instead_of_writing_garbage(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'wizardry',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ])->assertRedirect();

        $this->assertSame('general', LoopPermissionSetting::sole()->loop_type);
    }

    public function test_no_configuration_row_carries_a_loop_id(): void
    {
        $this->actingAs($this->superAdmin)->put(route('admin.loop-permissions.update'), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ]);

        $row = LoopPermissionSetting::sole()->toArray();

        $this->assertArrayNotHasKey('loop_id', $row);
        $this->assertArrayNotHasKey('organization_id', $row);
    }

    public function test_the_invariants_are_shown_but_never_as_cells(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('admin.loop-permissions'))->assertOk();

        $response->assertSee(__('loops.permissions_invariants_title'));
        // Present as text, absent as an input name.
        $this->assertStringNotContainsString('cells[tenant.isolation]', $response->getContent());
    }

    // ── Matrice Organization ────────────────────────────────────────────────

    public function test_the_organization_admin_reaches_their_matrix(): void
    {
        $this->actingAs($this->orgAdmin->fresh())
            ->get($this->orgUrl())
            ->assertOk()
            ->assertSee(__('loops.permissions_intro_organization'))
            ->assertSee($this->org->name);
    }

    public function test_a_standard_member_cannot_reach_the_organization_matrix(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)->get($this->orgUrl())->assertForbidden();
        $this->actingAs($user)->put($this->orgUrl(), ['type' => 'general'])->assertForbidden();
    }

    public function test_an_admin_of_another_organization_is_refused(): void
    {
        $otherAdmin = User::factory()->create();
        $otherOrg = Organization::factory()->create(['admin_id' => $otherAdmin->id]);
        $otherAdmin->update(['organization_id' => $otherOrg->id]);

        $this->actingAs($otherAdmin->fresh())->get($this->orgUrl())->assertForbidden();
    }

    public function test_an_organization_override_is_written_and_cleared(): void
    {
        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ])->assertRedirect();

        $this->assertSame(
            ['general' => ['facilitator' => ['manifesto.publish' => true]]],
            $this->org->fresh()->loop_permissions,
        );

        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'inherited']],
        ])->assertRedirect();

        $this->assertSame([], $this->org->fresh()->loop_permissions);
    }

    public function test_one_organization_never_affects_another(): void
    {
        $otherAdmin = User::factory()->create();
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'admin_id' => $otherAdmin->id]);
        $otherAdmin->update(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ]);

        $this->assertNull($otherOrg->fresh()->loop_permissions);
    }

    public function test_a_different_type_is_not_affected(): void
    {
        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'project',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ]);

        $this->assertNull($this->settings()->organizationOverride($this->org->fresh(), 'general', 'facilitator', 'manifesto.publish'));
        $this->assertTrue($this->settings()->organizationOverride($this->org->fresh(), 'project', 'facilitator', 'manifesto.publish'));
    }

    public function test_a_locked_permission_is_refused_on_the_organization_matrix(): void
    {
        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'general',
            'cells' => ['loops.manage_owners' => ['facilitator' => 'allowed']],
        ])->assertRedirect();

        $this->assertNull($this->org->fresh()->loop_permissions);
    }

    public function test_a_forged_cross_tenant_request_is_rejected(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);

        $this->actingAs($this->orgAdmin->fresh())
            ->put('/org/'.$otherOrg->slug.'/admin/loop-permissions', [
                'type' => 'general',
                'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
            ])
            ->assertForbidden();

        $this->assertNull($otherOrg->fresh()->loop_permissions);
    }

    public function test_the_affected_loop_count_folds_in_the_legacy_alias(): void
    {
        // Real Loops still carry `custom`, which reads as `general`.
        Loop::factory()->count(2)->create(['organization_id' => $this->org->id, 'type' => 'custom']);
        Loop::factory()->create(['organization_id' => $this->org->id, 'type' => 'general']);

        $count = $this->actingAs($this->orgAdmin->fresh())
            ->get($this->orgUrl('?type=general'))
            ->assertOk()
            ->viewData('affectedLoops');

        $this->assertSame(3, $count);
    }

    public function test_the_matrix_shows_the_effective_value_from_the_resolver(): void
    {
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);

        $modules = $this->actingAs($this->orgAdmin->fresh())
            ->get($this->orgUrl('?type=general'))
            ->assertOk()
            ->viewData('modules');

        $cell = $modules['manifesto']['manifesto.publish']['cells']['facilitator'];

        // Inherited here, but effectively allowed thanks to the global setting —
        // and the screen says exactly that.
        $this->assertSame('inherited', $cell['state']);
        $this->assertTrue($cell['effective']);
        $this->assertSame('global', $cell['source']);
    }

    public function test_the_organization_override_takes_precedence_in_the_display(): void
    {
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);
        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', false);

        $modules = $this->actingAs($this->orgAdmin->fresh())
            ->get($this->orgUrl('?type=general'))
            ->assertOk()
            ->viewData('modules');

        $cell = $modules['manifesto']['manifesto.publish']['cells']['facilitator'];

        $this->assertSame('denied', $cell['state']);
        $this->assertFalse($cell['effective']);
        $this->assertSame('organization', $cell['source']);
    }

    public function test_a_saved_override_actually_changes_what_a_facilitator_can_do(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'type' => 'general', 'status' => 'active']);
        app(LoopTypeRegistry::class)->applyPreset($loop);
        $facilitator = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create([
            'loop_id' => $loop->id, 'user_id' => $facilitator->id, 'role' => 'facilitator', 'status' => 'active',
        ]);

        $resolver = app(LoopPermissionResolver::class);
        $this->assertFalse($resolver->can($facilitator, $loop, 'manifesto.publish'));

        $this->actingAs($this->orgAdmin->fresh())->put($this->orgUrl(), [
            'type' => 'general',
            'cells' => ['manifesto.publish' => ['facilitator' => 'allowed']],
        ]);

        $this->assertTrue($resolver->can($facilitator, $loop->fresh(), 'manifesto.publish'));
    }
}
