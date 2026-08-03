<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two Loop administration screens: global and Organization-scoped.
 *
 * Beyond the columns themselves, two things are under test here — that changing
 * a type never destroys anything, and that the lists count in SQL instead of
 * hydrating every member of every Loop.
 */
class TASK1079LoopAdminTest extends TestCase
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
    }

    private function loop(array $overrides = []): Loop
    {
        return Loop::factory()->create(array_merge([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
        ], $overrides));
    }

    private function registry(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    // ── Admin global ────────────────────────────────────────────────────────

    public function test_the_global_list_shows_type_members_invitations_and_cards(): void
    {
        $loop = $this->loop(['name' => 'Boucle Admin 1079']);
        $this->registry()->applyPreset($loop);
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => $member->id, 'status' => 'active']);
        LoopInvitation::factory()->count(2)->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk();

        $response->assertSee('Boucle Admin 1079');
        $response->assertSee($this->registry()->label('general'));
        $response->assertSee($this->org->name);

        $listed = $response->viewData('loops')->firstWhere('id', $loop->id);
        $this->assertSame(1, $listed->active_members_count);
        $this->assertSame(2, $listed->invitations_count);
        $this->assertSame(2, $listed->pending_invitations_count);
        $this->assertSame(count($this->registry()->cardsFor('general')), $listed->enabled_cards_count);
    }

    public function test_the_pending_count_is_distinct_from_the_total(): void
    {
        $loop = $this->loop();
        LoopInvitation::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);
        LoopInvitation::factory()->revoked()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $listed = $this->actingAs($this->superAdmin)->get(route('admin.loops'))
            ->viewData('loops')->firstWhere('id', $loop->id);

        $this->assertSame(2, $listed->invitations_count);
        $this->assertSame(1, $listed->pending_invitations_count);
    }

    public function test_the_global_list_does_not_issue_a_query_per_loop(): void
    {
        foreach (range(1, 6) as $i) {
            $loop = $this->loop(['name' => "Boucle {$i}"]);
            $this->registry()->applyPreset($loop);
            LoopMember::factory()->create(['loop_id' => $loop->id, 'user_id' => User::factory()->create(['organization_id' => $this->org->id])->id]);
            LoopInvitation::factory()->create([
                'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
            ]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Counts are aggregated in the main query and cards eager-loaded once;
        // a per-row pattern would grow with the number of Loops.
        $this->assertLessThan(30, $count, "Query count {$count} suggests an N+1 on the Loops admin list");
    }

    public function test_a_super_admin_can_change_the_type(): void
    {
        $loop = $this->loop(['type' => 'general']);
        $this->registry()->applyPreset($loop);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'project'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('project', $loop->fresh()->type);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $loop = $this->loop(['type' => 'general']);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.type.update', $loop), ['type' => 'wizardry'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('general', $loop->fresh()->type);
    }

    // ── Admin Organization ──────────────────────────────────────────────────

    public function test_the_org_admin_can_edit_name_description_and_type(): void
    {
        $loop = $this->loop(['name' => 'Avant', 'description' => 'Ancienne', 'type' => 'general']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $loop->id]), [
                'name' => 'Après',
                'description' => 'Nouvelle description',
                'type' => 'training',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $loop->fresh();
        $this->assertSame('Après', $fresh->name);
        $this->assertSame('Nouvelle description', $fresh->description);
        $this->assertSame('training', $fresh->type);
    }

    public function test_the_org_admin_list_exposes_the_new_counters(): void
    {
        $loop = $this->loop();
        $this->registry()->applyPreset($loop);
        LoopMember::factory()->count(3)->create(['loop_id' => $loop->id, 'status' => 'active']);
        LoopInvitation::factory()->create([
            'loop_id' => $loop->id, 'organization_id' => $this->org->id, 'sender_id' => $this->orgAdmin->id,
        ]);

        $listed = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.loops', ['organization' => $this->org->slug]))
            ->assertOk()
            ->viewData('loops')->firstWhere('id', $loop->id);

        $this->assertSame(3, $listed->active_members_count);
        $this->assertSame(1, $listed->invitations_count);
        // activeMembers stays loaded on purpose: this screen manages members
        // inline. What matters is that the counters come from SQL.
        $this->assertTrue($listed->relationLoaded('activeMembers'));
    }

    public function test_an_org_admin_cannot_touch_a_loop_of_another_organization(): void
    {
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreignLoop = Loop::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Etrangere']);

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $foreignLoop->id]), [
                'name' => 'Detournee', 'type' => 'project',
            ])
            ->assertNotFound();

        $this->assertSame('Etrangere', $foreignLoop->fresh()->name);
    }

    public function test_a_standard_member_reaches_neither_admin_screen(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        $loop = $this->loop();

        $this->actingAs($member)->get(route('admin.loops'))->assertForbidden();
        $this->actingAs($member)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $loop->id]), [
                'name' => 'Pirate', 'type' => 'project',
            ])
            ->assertForbidden();
    }

    // ── Changement de type : rien n'est jamais perdu ────────────────────────

    public function test_the_full_type_rotation_through_the_admin_loses_no_card_or_content(): void
    {
        // Starting from peer_support, whose preset is the narrowest, so the
        // manually added card is genuinely outside it.
        $loop = $this->loop(['type' => 'peer_support']);
        $this->registry()->applyPreset($loop);
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'card_key' => 'core.ai_summary', 'enabled' => true, 'added_by_preset' => null,
        ]);
        $before = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        foreach (['project', 'training', 'peer_support', 'general'] as $type) {
            $this->actingAs($this->superAdmin)
                ->put(route('admin.loops.type.update', $loop), ['type' => $type])
                ->assertSessionHasNoErrors();
        }

        $after = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->sort()->values()->all();

        foreach ($before as $card) {
            $this->assertContains($card, $after, "Card {$card} lost during the type rotation");
        }
        $this->assertSame(count($after), count(array_unique($after)), 'A card was duplicated');
        // The card a human added is still there, still flagged as theirs.
        $this->assertNull(
            LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.ai_summary')->value('added_by_preset'),
        );
    }

    public function test_a_newly_created_loop_gets_its_type_preset(): void
    {
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['members_can_create_loops' => true, 'loop_mode' => 'multi']);
        app()->instance('current_organization', $this->org);

        $this->actingAs($creator)->post(route('loops.store'), ['name' => 'Née typée'])->assertRedirect();

        $loop = Loop::where('name', 'Née typée')->sole();
        $this->assertSame('general', $loop->type);
        $this->assertEqualsCanonicalizing(
            $this->registry()->cardsFor('general'),
            LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all(),
        );
    }
}
