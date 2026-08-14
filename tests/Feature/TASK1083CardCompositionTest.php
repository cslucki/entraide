<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configuring which cards a given Loop actually offers.
 *
 * The type provides a starting set; the Loop's composition is then its own.
 * Two properties matter more than the rest and are defended throughout:
 * switching a card off never deletes anything, and a preset synchronisation
 * never switches it back on.
 */
class TASK1083CardCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

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

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->org->id,
        ]);

        app()->instance('current_organization', $this->org);

        $this->loop = app(LoopService::class)->createLoop($this->superAdmin, 'Groupe de travail', null, 'private', null, Loop::ACCESS_REQUEST, null, 'project');
    }

    private function service(): LoopCardCompositionService
    {
        return app(LoopCardCompositionService::class);
    }

    private function adminUrl(?Loop $loop = null): string
    {
        return route('admin.loops.cards.update', $loop ?? $this->loop);
    }

    private function orgUrl(?Loop $loop = null, ?Organization $org = null): string
    {
        return route('organization.admin.loops.cards.update', [
            'organization' => ($org ?? $this->org)->slug,
            'loop' => ($loop ?? $this->loop)->id,
        ]);
    }

    private function isEnabled(string $key, ?Loop $loop = null): bool
    {
        return (bool) LoopCard::where('loop_id', ($loop ?? $this->loop)->id)
            ->where('card_key', $key)->value('enabled');
    }

    // ── Ce que la composition dit ───────────────────────────────────────────

    public function test_chatloop_is_never_offered_as_a_card(): void
    {
        $keys = array_column($this->service()->compositionFor($this->loop), 'key');

        $this->assertNotContains('core.chatloop', $keys);
        $this->assertNotContains('chatloop', $keys);
    }

    public function test_the_composition_says_where_each_card_comes_from(): void
    {
        $byKey = collect($this->service()->compositionFor($this->loop))->keyBy('key');

        $this->assertSame('preset', $byKey['core.manifesto']['origin']);
        $this->assertTrue($byKey['core.manifesto']['enabled']);
        $this->assertTrue($byKey['core.members']['protected'], 'Members keeps the workspace usable.');
    }

    public function test_a_card_added_locally_is_marked_as_such(): void
    {
        $dialogue = app(LoopService::class)->createLoop($this->superAdmin, 'Dialogue', null, 'private', null, Loop::ACCESS_REQUEST, null, 'general');
        $this->service()->enable($dialogue, 'core.roadmap');

        $byKey = collect($this->service()->compositionFor($dialogue))->keyBy('key');

        $this->assertSame('local', $byKey['core.roadmap']['origin']);
        // Never presented as the type's doing.
        $this->assertNull(LoopCard::where('loop_id', $dialogue->id)->where('card_key', 'core.roadmap')->value('added_by_preset'));
    }

    // ── Activer et désactiver ───────────────────────────────────────────────

    public function test_the_super_admin_switches_a_card_off_then_on(): void
    {
        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.roadmap', 'enabled' => 0])
            ->assertRedirect();

        $this->assertFalse($this->isEnabled('core.roadmap'));
        // Never deleted: the row stays, flagged.
        $this->assertDatabaseHas('loop_cards', ['loop_id' => $this->loop->id, 'card_key' => 'core.roadmap']);

        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.roadmap', 'enabled' => 1]);

        $this->assertTrue($this->isEnabled('core.roadmap'));
    }

    public function test_switching_a_card_off_keeps_its_content(): void
    {
        LoopRoadmapItem::factory()->count(3)->create(['loop_id' => $this->loop->id, 'organization_id' => $this->org->id]);

        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.roadmap', 'enabled' => 0]);

        $this->assertSame(3, LoopRoadmapItem::where('loop_id', $this->loop->id)->count());
    }

    public function test_switching_the_manifesto_off_keeps_the_root_document(): void
    {
        $documentId = $this->loop->fresh()->manifesto_blog_post_id;
        $this->assertNotNull($documentId);

        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.manifesto', 'enabled' => 0]);

        $this->assertDatabaseHas('blog_posts', ['id' => $documentId]);
        $this->assertSame($documentId, $this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_the_members_card_cannot_be_switched_off(): void
    {
        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.members', 'enabled' => 0])
            ->assertSessionHas('error');

        $this->assertTrue($this->isEnabled('core.members'));
    }

    public function test_an_unknown_card_key_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->put($this->adminUrl(), ['card_key' => 'core.invented', 'enabled' => 1])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('loop_cards', ['loop_id' => $this->loop->id, 'card_key' => 'core.invented']);
    }

    public function test_enabling_twice_is_idempotent(): void
    {
        $this->service()->enable($this->loop, 'core.roadmap');
        $this->service()->enable($this->loop, 'core.roadmap');

        $this->assertSame(1, LoopCard::where('loop_id', $this->loop->id)->where('card_key', 'core.roadmap')->count());
    }

    // ── Le workspace suit ───────────────────────────────────────────────────

    public function test_a_card_switched_off_leaves_the_workspace(): void
    {
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->orgAdmin->id, 'joined_at' => now(),
        ]);

        $label = __(config('loop_cards.cards')['core.roadmap']['label_key']);

        $this->actingAs($this->orgAdmin)->get(route('loops.show', $this->loop))->assertSee($label);

        $this->service()->disable($this->loop, 'core.roadmap');

        $this->actingAs($this->orgAdmin)->get(route('loops.show', $this->loop))->assertDontSee($label);
    }

    // ── Synchronisation des presets ─────────────────────────────────────────

    public function test_a_preset_synchronisation_never_switches_a_card_back_on(): void
    {
        // The central guarantee of this task.
        $this->service()->disable($this->loop, 'core.roadmap');

        app(LoopTypeRegistry::class)->applyPreset($this->loop->fresh());

        $this->assertFalse($this->isEnabled('core.roadmap'));
    }

    public function test_a_locally_added_card_survives_a_synchronisation(): void
    {
        $dialogue = app(LoopService::class)->createLoop($this->superAdmin, 'Dialogue 2', null, 'private', null, Loop::ACCESS_REQUEST, null, 'general');
        $this->service()->enable($dialogue, 'core.roadmap');

        app(LoopTypeRegistry::class)->applyPreset($dialogue->fresh());

        $this->assertTrue($this->isEnabled('core.roadmap', $dialogue));
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_the_organization_admin_configures_a_loop_of_its_organization(): void
    {
        $this->actingAs($this->orgAdmin)
            ->put($this->orgUrl(), ['card_key' => 'core.roadmap', 'enabled' => 0])
            ->assertRedirect();

        $this->assertFalse($this->isEnabled('core.roadmap'));
    }

    public function test_a_forged_loop_id_from_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'status' => 'active']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.cards.update', [
                'organization' => $this->org->slug, 'loop' => $foreignLoop->id,
            ]), ['card_key' => 'core.roadmap', 'enabled' => 0])
            ->assertNotFound();
    }

    public function test_a_member_without_the_permission_is_refused(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($member)
            ->put($this->orgUrl(), ['card_key' => 'core.roadmap', 'enabled' => 0])
            ->assertForbidden();

        $this->assertTrue($this->isEnabled('core.roadmap'));
    }

    // ── Écrans ──────────────────────────────────────────────────────────────

    public function test_the_platform_admin_screen_shows_the_composition(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))
            ->assertOk()
            ->assertSee(__('loops.cards_composition_title'))
            ->assertSee(__('loops.cards_origin_preset'));
    }

    public function test_the_organization_admin_screen_shows_the_composition(): void
    {
        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops.edit', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->assertSee(__('loops.cards_composition_title'));
    }

    public function test_no_native_confirmation_is_used(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))->assertOk()->getContent();

        $this->assertStringNotContainsString('wire:confirm', $html);
        $this->assertStringNotContainsString('window.confirm', $html);
    }
}
