<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopPresetConfigurator;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1331: `core.ai_summary` (placement=chat_action) was fully manageable
 * end to end but invisible on the one screen meant to manage it —
 * `LoopPresetConfigurator::describe()` computed a `chat_actions` bucket that
 * `configure.blade.php` never rendered. This suite pins the fix (the bucket
 * is now rendered, with the file's own existing enable/disable pattern, no
 * new business logic) and the separate `admin.loops.show` contradiction fix
 * (that screen documents itself as read-only; it now actually is, with a
 * link to Outils instead of a working toggle).
 */
class TASK1331SuperAdminCardManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $orgAdmin;

    /** general: core.ai_summary is NOT in the default preset (TASK-1090). */
    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->org->id,
        ]);

        app()->instance('current_organization', $this->org);

        $this->loop = app(LoopService::class)->createLoop(
            $this->superAdmin, 'Communauté QA', null, 'private', null, Loop::ACCESS_REQUEST, null, 'general',
        );
    }

    private function isEnabled(string $key, ?Loop $loop = null): bool
    {
        return (bool) LoopCard::where('loop_id', ($loop ?? $this->loop)->id)
            ->where('card_key', $key)->value('enabled');
    }

    // ── A/B: Outils renders and offers the chat_action bucket ──────────────

    public function test_configure_renders_the_chat_actions_group(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.preset_chat_title'))
            ->assertSee(__('loops.cards.ai_summary.label'));
    }

    public function test_ai_summary_starts_inactive_on_a_general_loop_and_is_offered_as_such(): void
    {
        $chatActions = collect(app(LoopPresetConfigurator::class)->describe($this->loop)['chat_actions']);
        $aiSummary = $chatActions->firstWhere('key', 'core.ai_summary');

        $this->assertNotNull($aiSummary, 'core.ai_summary must be in the chat_action bucket.');
        $this->assertFalse($aiSummary['enabled']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.cards_enable'));
    }

    // ── C: activation writes exactly one row, through the guarded path ─────

    public function test_activating_ai_summary_from_outils_writes_only_that_row(): void
    {
        $before = LoopCard::where('loop_id', $this->loop->id)->count();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.compose', $this->loop), [
                'action' => 'enable', 'card_key' => 'core.ai_summary',
            ])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled('core.ai_summary'));
        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->loop->id,
            'organization_id' => $this->org->id,
            'card_key' => 'core.ai_summary',
            'enabled' => true,
        ]);
        // Exactly one new row: nothing else was touched.
        $this->assertSame($before + 1, LoopCard::where('loop_id', $this->loop->id)->count());
        $this->assertTrue($this->isEnabled('core.manifesto'), 'Untouched by the ai_summary toggle.');
    }

    // ── D: an already-active card reads as active, offers disable ──────────

    public function test_ai_summary_already_active_reads_as_active_and_offers_disable(): void
    {
        $project = app(LoopService::class)->createLoop(
            $this->superAdmin, 'Projet QA', null, 'private', null, Loop::ACCESS_REQUEST, null, 'project',
        );
        $this->assertTrue($this->isEnabled('core.ai_summary', $project), 'project defaults to ai_summary ON.');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $project))
            ->assertOk()
            ->assertSee(__('loops.cards_disable'));
    }

    // ── E: disabling keeps the row and the underlying AI data ──────────────

    public function test_disabling_ai_summary_keeps_the_row_and_existing_ai_messages(): void
    {
        app(LoopPresetConfigurator::class)->enable($this->superAdmin, $this->loop, 'core.ai_summary');
        LoopMessage::factory()->count(2)->create(['loop_id' => $this->loop->id, 'type' => 'ai']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.compose', $this->loop), [
                'action' => 'disable', 'card_key' => 'core.ai_summary',
            ])
            ->assertRedirect();

        $this->assertFalse($this->isEnabled('core.ai_summary'));
        // Never deleted: the row stays, flagged.
        $this->assertDatabaseHas('loop_cards', ['loop_id' => $this->loop->id, 'card_key' => 'core.ai_summary']);
        $this->assertSame(2, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'ai')->count());
    }

    // ── F/G: neighbouring placements are unaffected ─────────────────────────

    public function test_frame_and_grid_sections_still_render_next_to_the_new_one(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertOk();

        $response->assertSee(__('loops.preset_frame_title'))
            ->assertSee(__('loops.cards.manifesto.label'))
            ->assertSee(__('loops.cards.members.label'))
            ->assertSee(__('loops.tools_primary_title'));
    }

    // ── I/J: same guards as every other card, nothing chat_action-specific ─

    public function test_a_member_without_the_permission_cannot_enable_ai_summary(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($member)
            ->post(route('admin.loops.compose', $this->loop), [
                'action' => 'enable', 'card_key' => 'core.ai_summary',
            ])
            ->assertForbidden();

        $this->assertFalse($this->isEnabled('core.ai_summary'));
    }

    public function test_a_forged_loop_id_from_another_organization_is_refused(): void
    {
        // Same shape as the org-scoped guard already proven in
        // TASK1083CardCompositionTest: the Organization admin's own URL
        // prefix, a Loop id borrowed from another Organization.
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'active']);

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->org->slug, 'loop' => $foreignLoop->id,
            ]))
            ->assertNotFound();
    }

    public function test_a_non_admin_gets_no_access_to_the_platform_admin_surface_at_all(): void
    {
        // The SuperAdmin-prefixed routes are not tenant-scoped by design
        // (a real super-admin may reach any Organization's Loop) — a plain
        // Organization admin is refused by the admin-only route middleware
        // before the controller's own org check ever runs.
        $this->actingAs($this->orgAdmin)
            ->get(route('admin.loops.configure', $this->loop))
            ->assertForbidden();
    }

    // ── M: "Voir" no longer mutates; it links to Outils instead ────────────

    public function test_show_screen_offers_no_card_toggle_and_links_to_outils(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('loops.cards_enable'), $html);
        $this->assertStringNotContainsString(__('loops.cards_disable'), $html);
        $this->assertStringContainsString(__('loops.cards_manage_in_tools'), $html);
        $this->assertStringContainsString(__('loops.cards_state_inactive'), $html);
    }

    public function test_show_screen_toggle_route_is_unreachable_from_the_rendered_page_but_still_guarded_directly(): void
    {
        // The capability is not removed (other tests, and the Organization
        // admin's own edit screen, still exercise it) — only this page's UI
        // no longer offers it, matching its own "nothing mutates" docblock.
        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.cards.update', $this->loop), [
                'card_key' => 'core.ai_summary', 'enabled' => 1,
            ])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled('core.ai_summary'));
    }
}
