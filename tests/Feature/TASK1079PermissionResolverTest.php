<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopPermissionSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The permission resolver and its four configuration layers.
 *
 * Two things are defended here beyond the matrix itself: that the platform and
 * Organization authorities do not need a membership, and that no configuration
 * anywhere carries a loop_id.
 */
class TASK1079PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        app()->instance('current_organization', $this->org);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    private function settings(): LoopPermissionSettingsService
    {
        return app(LoopPermissionSettingsService::class);
    }

    private function memberWithRole(string $role): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        return $user;
    }

    // ── Autorités : membership non requis (comportement figé) ───────────────

    public function test_a_super_admin_needs_no_membership(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->assertTrue($this->resolver()->can($superAdmin, $this->loop, 'manifesto.publish'));
        $this->assertTrue($this->resolver()->can($superAdmin, $this->loop, 'loops.change_type'));
    }

    public function test_an_organization_admin_needs_no_membership(): void
    {
        $this->assertSame(0, LoopMember::where('user_id', $this->orgAdmin->id)->count());

        $this->assertTrue($this->resolver()->can($this->orgAdmin->fresh(), $this->loop, 'manifesto.publish'));
        $this->assertTrue($this->resolver()->can($this->orgAdmin->fresh(), $this->loop, 'loops.manage_owners'));
    }

    public function test_an_admin_of_another_organization_is_refused(): void
    {
        $foreignAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        Organization::factory()->create(['admin_id' => $foreignAdmin->id]);

        $this->assertFalse($this->resolver()->can($foreignAdmin->fresh(), $this->loop, 'manifesto.update'));
    }

    public function test_a_user_of_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreigner = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->resolver()->can($foreigner, $this->loop, 'loops.view'));
    }

    public function test_a_deactivated_account_is_refused_whatever_its_authority(): void
    {
        $banned = User::factory()->create([
            'organization_id' => $this->org->id, 'is_admin' => true, 'banned_at' => now(),
        ]);

        $this->assertFalse($this->resolver()->can($banned, $this->loop, 'loops.view'));
    }

    public function test_a_non_member_without_authority_is_refused(): void
    {
        $outsider = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertFalse($this->resolver()->can($outsider, $this->loop, 'loops.view'));
    }

    public function test_an_inactive_membership_grants_nothing(): void
    {
        $user = $this->memberWithRole('owner');
        LoopMember::where('user_id', $user->id)->update(['status' => 'left']);

        $this->assertFalse($this->resolver()->can($user, $this->loop, 'loops.view'));
    }

    // ── Socle par rôle ──────────────────────────────────────────────────────

    public function test_the_owner_baseline(): void
    {
        $owner = $this->memberWithRole('owner');

        foreach (['loops.change_type', 'loops.manage_owners', 'manifesto.publish', 'chatloop.manage'] as $p) {
            $this->assertTrue($this->resolver()->can($owner, $this->loop, $p), "owner should have {$p}");
        }
    }

    public function test_the_facilitator_baseline(): void
    {
        $facilitator = $this->memberWithRole('facilitator');

        foreach (['manifesto.update', 'manifesto.manage_sources', 'roadmap.manage', 'chatloop.manage', 'loop_members.invite'] as $p) {
            $this->assertTrue($this->resolver()->can($facilitator, $this->loop, $p), "facilitator should have {$p}");
        }

        foreach (['manifesto.publish', 'loops.change_type', 'loops.manage_owners', 'loops.manage_cards', 'loops.archive'] as $p) {
            $this->assertFalse($this->resolver()->can($facilitator, $this->loop, $p), "facilitator should not have {$p}");
        }
    }

    public function test_the_member_baseline(): void
    {
        $member = $this->memberWithRole('member');

        $this->assertTrue($this->resolver()->can($member, $this->loop, 'chatloop.post'));
        $this->assertTrue($this->resolver()->can($member, $this->loop, 'manifesto.view'));

        foreach (['manifesto.update', 'roadmap.manage', 'loop_members.invite'] as $p) {
            $this->assertFalse($this->resolver()->can($member, $this->loop, $p), "member should not have {$p}");
        }
    }

    public function test_a_legacy_moderator_gets_facilitator_capabilities(): void
    {
        $legacy = $this->memberWithRole('moderator');
        $facilitator = $this->memberWithRole('facilitator');

        foreach (['manifesto.update', 'roadmap.manage', 'chatloop.manage', 'manifesto.publish', 'loops.change_type'] as $p) {
            $this->assertSame(
                $this->resolver()->can($facilitator, $this->loop, $p),
                $this->resolver()->can($legacy, $this->loop, $p),
                "moderator and facilitator must agree on {$p}",
            );
        }
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $owner = $this->memberWithRole('owner');

        $this->assertFalse($this->resolver()->can($owner, $this->loop, 'loops.summon_dragon'));
    }

    // ── Variation par type ──────────────────────────────────────────────────

    public function test_training_lets_the_facilitator_publish_the_manifesto(): void
    {
        $facilitator = $this->memberWithRole('facilitator');

        $this->assertFalse($this->resolver()->can($facilitator, $this->loop, 'manifesto.publish'));

        $this->loop->update(['type' => 'training']);

        $this->assertTrue($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.publish'));
    }

    public function test_peer_support_hides_the_member_list_from_members(): void
    {
        $member = $this->memberWithRole('member');

        $this->assertTrue($this->resolver()->can($member, $this->loop, 'loop_members.view'));

        $this->loop->update(['type' => 'peer_support']);

        $this->assertFalse($this->resolver()->can($member, $this->loop->fresh(), 'loop_members.view'));
    }

    public function test_a_type_declaring_nothing_inherits_the_baseline_whole(): void
    {
        $resolver = $this->resolver();

        foreach (array_keys(config('loop_permissions.permissions')) as $p) {
            // `project` declares no override at all.
            $this->assertSame(
                $resolver->systemValue('general', 'facilitator', $p),
                $resolver->systemValue('project', 'facilitator', $p),
                "project must inherit {$p} from the baseline",
            );
        }
    }

    public function test_the_legacy_custom_type_resolves_as_general(): void
    {
        $this->loop->update(['type' => 'custom']);
        $facilitator = $this->memberWithRole('facilitator');

        $this->assertTrue($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.update'));
        $this->assertFalse($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.publish'));
    }

    // ── Réglage global ──────────────────────────────────────────────────────

    public function test_a_global_setting_overrides_the_baseline(): void
    {
        $facilitator = $this->memberWithRole('facilitator');
        $this->assertFalse($this->resolver()->can($facilitator, $this->loop, 'manifesto.publish'));

        $this->assertTrue($this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true));

        $this->assertTrue($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.publish'));
    }

    public function test_clearing_a_global_setting_returns_to_the_default(): void
    {
        $facilitator = $this->memberWithRole('facilitator');
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);

        $this->settings()->clearGlobal('general', 'facilitator', 'manifesto.publish');

        $this->assertFalse($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.publish'));
        $this->assertSame(0, LoopPermissionSetting::count(), 'Clearing must delete the row, not store a value');
    }

    public function test_the_matrix_is_never_materialised(): void
    {
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);

        // One deliberate change, one row. Nothing else is written.
        $this->assertSame(1, LoopPermissionSetting::count());
    }

    public function test_no_configuration_anywhere_carries_a_loop_id(): void
    {
        $this->assertFalse(Schema::hasColumn('loop_permission_settings', 'loop_id'));
        $this->assertFalse(Schema::hasColumn('loop_permission_settings', 'organization_id'));

        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', true);
        $json = json_encode($this->org->fresh()->loop_permissions);
        $this->assertStringNotContainsString('loop_id', (string) $json);
        $this->assertStringNotContainsString($this->loop->id, (string) $json);
    }

    // ── Surcharge Organization ──────────────────────────────────────────────

    public function test_an_organization_override_beats_the_global_setting(): void
    {
        $facilitator = $this->memberWithRole('facilitator');
        $this->settings()->setGlobal('general', 'facilitator', 'manifesto.publish', true);
        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', false);

        $this->assertFalse($this->resolver()->can($facilitator, $this->loop->fresh(), 'manifesto.publish'));
    }

    public function test_the_organization_json_stays_sparse(): void
    {
        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', true);

        $this->assertSame(
            ['general' => ['facilitator' => ['manifesto.publish' => true]]],
            $this->org->fresh()->loop_permissions,
        );
    }

    public function test_clearing_an_organization_override_prunes_the_json(): void
    {
        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', true);

        $this->settings()->clearOrganization($this->org->fresh(), 'general', 'facilitator', 'manifesto.publish');

        // Not an empty scaffold left behind — the containers are pruned.
        $this->assertSame([], $this->org->fresh()->loop_permissions);
    }

    public function test_one_organization_does_not_affect_another(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $otherLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'type' => 'general', 'status' => 'active']);
        $otherFacilitator = User::factory()->create(['organization_id' => $otherOrg->id]);
        LoopMember::factory()->create([
            'loop_id' => $otherLoop->id, 'user_id' => $otherFacilitator->id,
            'role' => 'facilitator', 'status' => 'active',
        ]);

        $this->settings()->setOrganization($this->org, 'general', 'facilitator', 'manifesto.publish', true);

        $this->assertFalse($this->resolver()->can($otherFacilitator, $otherLoop, 'manifesto.publish'));
    }

    public function test_an_unknown_key_is_never_stored(): void
    {
        $this->assertFalse($this->settings()->setOrganization($this->org, 'wizardry', 'facilitator', 'manifesto.publish', true));
        $this->assertFalse($this->settings()->setOrganization($this->org, 'general', 'wizard', 'manifesto.publish', true));
        $this->assertFalse($this->settings()->setOrganization($this->org, 'general', 'facilitator', 'loops.summon_dragon', true));

        $this->assertNull($this->org->fresh()->loop_permissions);
    }

    public function test_a_hand_edited_payload_cannot_smuggle_a_key_past_validation(): void
    {
        // Written straight to the column, bypassing the service.
        $this->org->forceFill(['loop_permissions' => [
            'general' => ['facilitator' => ['manifesto.publish' => true]],
            'wizardry' => ['wizard' => ['loops.summon_dragon' => true]],
        ]])->save();

        $clean = $this->settings()->normalize($this->org->fresh()->loop_permissions);

        $this->assertArrayHasKey('general', $clean);
        $this->assertArrayNotHasKey('wizardry', $clean);
    }

    // ── Permissions verrouillées ────────────────────────────────────────────

    public function test_a_locked_permission_cannot_be_persisted_anywhere(): void
    {
        $this->assertTrue($this->settings()->isLocked('loops.manage_owners'));

        $this->assertFalse($this->settings()->setGlobal('general', 'facilitator', 'loops.manage_owners', true));
        $this->assertFalse($this->settings()->setOrganization($this->org, 'general', 'facilitator', 'loops.manage_owners', true));

        $this->assertSame(0, LoopPermissionSetting::count());
        $this->assertNull($this->org->fresh()->loop_permissions);
    }

    public function test_a_locked_permission_answers_from_the_system_baseline(): void
    {
        $facilitator = $this->memberWithRole('facilitator');
        $owner = $this->memberWithRole('owner');

        $this->assertFalse($this->resolver()->can($facilitator, $this->loop, 'loops.manage_owners'));
        $this->assertTrue($this->resolver()->can($owner, $this->loop, 'loops.manage_owners'));
    }

    public function test_invariants_are_not_permissions(): void
    {
        $invariants = array_keys(config('loop_permissions.invariants'));
        $permissions = array_keys(config('loop_permissions.permissions'));

        // Descriptive only: no invariant is resolvable or persistable.
        foreach ($invariants as $key) {
            $this->assertNotContains($key, $permissions, "{$key} must not be a permission");
            $this->assertFalse($this->settings()->permissionExists($key));
            $this->assertFalse($this->settings()->setGlobal('general', 'facilitator', $key, false));
        }
    }

    // ── Dépendance à une Card ───────────────────────────────────────────────

    public function test_a_card_dependent_permission_needs_its_card(): void
    {
        $owner = $this->memberWithRole('owner');
        $this->assertTrue($this->resolver()->can($owner, $this->loop, 'manifesto.update'));

        LoopCard::where('loop_id', $this->loop->id)->where('card_key', 'core.manifesto')->update(['enabled' => false]);

        $this->assertFalse($this->resolver()->can($owner, $this->loop->fresh(), 'manifesto.update'));
    }

    public function test_a_structural_permission_ignores_card_composition(): void
    {
        $owner = $this->memberWithRole('owner');
        LoopCard::where('loop_id', $this->loop->id)->update(['enabled' => false]);

        // loops.manage_owners declares no card dependency and must not be
        // collaterally denied.
        $this->assertTrue($this->resolver()->can($owner, $this->loop->fresh(), 'loops.manage_owners'));
    }

    public function test_every_declared_card_dependency_exists_in_the_catalogue(): void
    {
        $cards = array_keys(config('loop_cards.cards'));

        foreach (config('loop_permissions.permissions') as $key => $definition) {
            if (isset($definition['requires_card'])) {
                $this->assertContains($definition['requires_card'], $cards, "{$key} depends on an unknown card");
            }
        }
    }

    public function test_permission_keys_with_a_dot_resolve_by_array_access(): void
    {
        // The CP5bis bug: config() dot-notation splits "core.manifesto".
        $this->assertSame('core.manifesto', $this->settings()->requiredCard('manifesto.publish'));
        $this->assertNull($this->settings()->requiredCard('loops.manage_owners'));
    }

    // ── Rôles et types inconnus ─────────────────────────────────────────────

    public function test_an_unknown_type_falls_back_to_the_default_baseline(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(
            $resolver->systemValue('general', 'facilitator', 'manifesto.update'),
            $resolver->systemValue('wizardry', 'facilitator', 'manifesto.update'),
        );
    }

    public function test_an_unknown_role_gets_the_least_privileged_baseline(): void
    {
        $resolver = $this->resolver();

        $this->assertSame(
            $resolver->systemValue('general', LoopRoleRegistry::MEMBER, 'manifesto.update'),
            $resolver->systemValue('general', 'wizard', 'manifesto.update'),
        );
    }
}
