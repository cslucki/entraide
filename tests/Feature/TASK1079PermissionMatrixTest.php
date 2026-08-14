<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopPermissionSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    // ── Ce qui est enregistré doit se voir ──────────────────────────────────

    public function test_a_saved_setting_renders_exactly_one_checked_segment(): void
    {
        // The matrix used to render two copies of every cell — a desktop table
        // and a mobile stack — sharing one radio `name`. Radios group by name,
        // so the browser kept only the last copy checked and the visible
        // desktop control showed no selection at all for a saved setting.
        $this->settings()->setGlobal('general', 'facilitator', 'loops.update_identity', true);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['type' => 'general']))
            ->assertOk()
            ->getContent();

        $pattern = '/name="cells\[loops\.update_identity\]\[facilitator\]"[^>]*value="allowed"[^>]*\schecked\b/';
        $this->assertSame(1, preg_match_all($pattern, $html), 'The saved value must be checked exactly once.');

        // And exactly one input per state, so no hidden duplicate can steal it.
        $inputs = preg_match_all('/name="cells\[loops\.update_identity\]\[facilitator\]"/', $html);
        $this->assertSame(3, $inputs, 'One input per state, and only one set of them.');
    }

    public function test_each_role_carries_its_own_colour_on_a_configured_cell(): void
    {
        foreach (['owner' => 'violet', 'facilitator' => 'sky', 'member' => 'amber'] as $role => $colour) {
            $this->settings()->setGlobal('general', $role, 'loops.update_identity', true);
        }

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-permissions', ['type' => 'general']))
            ->assertOk()
            ->getContent();

        foreach (['violet', 'sky', 'amber'] as $colour) {
            $this->assertStringContainsString('ring-'.$colour.'-400', $html);
        }
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

    // ── Couche Organization : conservée sans écran dédié ────────────────────

    public function test_no_organization_scoped_matrix_route_exists(): void
    {
        // Removed on review: the matrix is a platform-level tool, not something
        // an Organization administers.
        $this->assertFalse(Route::has('organization.admin.loop-permissions'));

        $this->actingAs($this->orgAdmin->fresh())
            ->get('/org/'.$this->org->slug.'/admin/loop-permissions')
            ->assertNotFound();
    }

    public function test_the_organization_override_layer_is_still_available(): void
    {
        // The screen is gone; the capability is not. It stays resolvable and
        // writable through the service, ready for whatever surfaces it later.
        $this->assertTrue(
            $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', true),
        );
        $this->assertSame(
            ['general' => ['facilitator' => ['manifesto.publish' => true]]],
            $this->org->fresh()->loop_permissions,
        );
    }

    public function test_an_organization_override_still_beats_the_global_setting(): void
    {
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);
        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', false);

        $this->assertFalse(
            app(LoopPermissionResolver::class)->resolveForRole($this->org->fresh(), 'general', 'facilitator', 'manifesto.publish'),
        );
    }
}
