<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopRoadmapCard;
use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoopRoadmapCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;      // owner of the loop

    private User $member;     // regular active member

    private User $moderator;  // moderator member

    private User $nonMember;  // same org, not a member

    private User $crossUser;  // other org

    private Loop $loop;

    private Loop $otherLoop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->moderator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $this->service = new LoopService;

        $this->loop = $this->service->createLoop($this->owner, 'Roadmap Loop'); // owner role
        $this->service->addMember($this->loop, $this->member, 'member');
        $this->service->addMember($this->loop, $this->moderator, 'moderator');

        $this->otherLoop = $this->service->createLoop($this->crossUser, 'Other Org Loop');
    }

    private function item(array $attrs = []): LoopRoadmapItem
    {
        return LoopRoadmapItem::factory()->create(array_merge([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id,
        ], $attrs));
    }

    public function test_member_sees_empty_state(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->assertSet('newTitle', '')
            ->assertSee(__('loops.roadmap_empty_pitch'));
    }

    public function test_member_can_add_an_action(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Rédiger le prévisionnel')
            ->call('createAction')
            ->assertSet('newTitle', '')
            ->assertSee('Rédiger le prévisionnel');

        $this->assertDatabaseHas('loop_roadmap_items', [
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'title' => 'Rédiger le prévisionnel',
            'status' => 'todo',
            'created_by' => $this->member->id,
        ]);
    }

    public function test_add_requires_a_title(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', '   ')
            ->call('createAction')
            ->assertSet('errorMessage', __('loops.roadmap_title_required'));

        $this->assertDatabaseCount('loop_roadmap_items', 0);
    }

    public function test_member_can_toggle_done_and_reopen(): void
    {
        $item = $this->item(['title' => 'To complete']);
        $this->actingAs($this->member);

        $c = Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('toggle', $item->id);
        $this->assertSame('done', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->completed_at);

        $c->call('toggle', $item->id);
        $this->assertSame('todo', $item->fresh()->status);
        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_creator_can_edit_title(): void
    {
        $item = $this->item(['created_by' => $this->member->id, 'title' => 'Old']);
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingTitle', 'New title')
            ->call('saveEdit');

        $this->assertSame('New title', $item->fresh()->title);
    }

    public function test_regular_member_cannot_edit_someone_elses_action(): void
    {
        $item = $this->item(['created_by' => $this->owner->id, 'title' => 'Owner item']);
        $this->actingAs($this->member);

        // startEdit is refused (not creator, not privileged) -> editingId stays null
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->assertSet('editingId', null);

        $this->assertSame('Owner item', $item->fresh()->title);
    }

    public function test_moderator_can_edit_others_actions(): void
    {
        $item = $this->item(['created_by' => $this->member->id, 'title' => 'Member item']);
        $this->actingAs($this->moderator);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingTitle', 'Edited by moderator')
            ->call('saveEdit');

        $this->assertSame('Edited by moderator', $item->fresh()->title);
    }

    public function test_delete_authorized_for_owner_refused_for_non_creator(): void
    {
        $item = $this->item(['created_by' => $this->owner->id]);

        // Non-creator regular member cannot delete
        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('deleteItem', $item->id);
        $this->assertDatabaseHas('loop_roadmap_items', ['id' => $item->id]);

        // Owner can delete
        $this->actingAs($this->owner);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('deleteItem', $item->id);
        $this->assertDatabaseMissing('loop_roadmap_items', ['id' => $item->id]);
    }

    public function test_cannot_touch_item_of_another_loop_or_organization(): void
    {
        $foreign = LoopRoadmapItem::factory()->create([
            'loop_id' => $this->otherLoop->id,
            'organization_id' => $this->otherLoop->organization_id,
            'created_by' => $this->crossUser->id,
            'title' => 'Foreign',
            'status' => 'todo',
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('toggle', $foreign->id);

        // Unchanged: the foreign id is not resolvable within this loop/org.
        $this->assertSame('todo', $foreign->fresh()->status);
    }

    public function test_assignee_must_be_active_member_of_the_loop(): void
    {
        $item = $this->item(['created_by' => $this->owner->id]);
        $this->actingAs($this->owner);

        // Valid: assign to an active member
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingAssignees', [$this->member->id])
            ->call('saveEdit');
        $this->assertEqualsCanonicalizing([$this->member->id], $item->fresh()->assignees->pluck('id')->all());

        // Invalid: assign to a non-member -> refused, error message
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingAssignees', [$this->nonMember->id])
            ->call('saveEdit')
            ->assertSet('errorMessage', __('loops.roadmap_invalid_assignee'));
        $this->assertEqualsCanonicalizing([$this->member->id], $item->fresh()->assignees->pluck('id')->all());
    }

    public function test_assign_supports_up_to_three_members_and_rejects_four(): void
    {
        $m2 = User::factory()->create(['organization_id' => $this->organization->id]);
        $m3 = User::factory()->create(['organization_id' => $this->organization->id]);
        $m4 = User::factory()->create(['organization_id' => $this->organization->id]);
        foreach ([$m2, $m3, $m4] as $u) {
            $this->service->addMember($this->loop, $u, 'member');
        }
        $item = $this->item(['created_by' => $this->owner->id]);

        $this->actingAs($this->owner);
        // Three is allowed.
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assign', $item->id, [$this->member->id, $m2->id, $m3->id]);
        $this->assertCount(3, $item->fresh()->assignees);

        // Four is refused, previous set unchanged.
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assign', $item->id, [$this->member->id, $m2->id, $m3->id, $m4->id])
            ->assertSet('errorMessage', __('loops.roadmap_invalid_assignee'));
        $this->assertCount(3, $item->fresh()->assignees);
    }

    public function test_non_member_cannot_manage(): void
    {
        $this->actingAs($this->nonMember);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->assertSet('newTitle', '')
            ->set('newTitle', 'Should not persist')
            ->call('createAction');

        $this->assertDatabaseCount('loop_roadmap_items', 0);
    }

    public function test_deactivated_member_cannot_add(): void
    {
        $this->member->update(['banned_at' => now()]);
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Nope')
            ->call('createAction');

        $this->assertDatabaseCount('loop_roadmap_items', 0);
    }

    public function test_reorder_swaps_positions(): void
    {
        $a = $this->item(['title' => 'A', 'position' => 0]);
        $b = $this->item(['title' => 'B', 'position' => 1]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('moveUp', $b->id);

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_open_counter_reflects_open_items(): void
    {
        $this->item(['status' => 'todo', 'position' => 0]);
        $this->item(['status' => 'todo', 'position' => 1]);
        LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id, 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->assertViewHas('openCount', 2);
    }

    // ---------------------------------------------------------------------
    // Drag & drop reorder (reorderGroup) — per status group.
    // ---------------------------------------------------------------------

    public function test_reorder_group_persists_new_order(): void
    {
        $a = $this->item(['title' => 'A', 'status' => 'todo', 'position' => 0]);
        $b = $this->item(['title' => 'B', 'status' => 'todo', 'position' => 1]);
        $c = $this->item(['title' => 'C', 'status' => 'todo', 'position' => 2]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$c->id, $a->id, $b->id]);

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_reorder_group_first_to_last_and_stable_after_reload(): void
    {
        $a = $this->item(['title' => 'A', 'status' => 'todo', 'position' => 0]);
        $b = $this->item(['title' => 'B', 'status' => 'todo', 'position' => 1]);
        $c = $this->item(['title' => 'C', 'status' => 'todo', 'position' => 2]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$b->id, $c->id, $a->id]);

        $ordered = LoopRoadmapItem::query()
            ->where('loop_id', $this->loop->id)
            ->open()->orderBy('position')->pluck('id')->all();

        $this->assertSame([$b->id, $c->id, $a->id], $ordered);
    }

    public function test_reorder_group_only_touches_its_own_status(): void
    {
        $o1 = $this->item(['status' => 'todo', 'position' => 0]);
        $o2 = $this->item(['status' => 'todo', 'position' => 1]);
        $d1 = LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id, 'created_by' => $this->owner->id, 'position' => 0,
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$o2->id, $o1->id]);

        $this->assertSame(0, $o2->fresh()->position);
        $this->assertSame(1, $o1->fresh()->position);
        $this->assertSame(0, $d1->fresh()->position); // untouched
    }

    public function test_reorder_group_rejects_invalid_status(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'archived', [$b->id, $a->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
    }

    public function test_reorder_group_rejects_duplicate_ids(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$a->id, $a->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
    }

    public function test_reorder_group_rejects_incomplete_set(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);
        $c = $this->item(['status' => 'todo', 'position' => 2]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$b->id, $a->id]) // missing $c
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
        $this->assertSame(2, $c->fresh()->position);
    }

    public function test_reorder_group_rejects_wrong_status_member_of_set(): void
    {
        $o1 = $this->item(['status' => 'todo', 'position' => 0]);
        $o2 = $this->item(['status' => 'todo', 'position' => 1]);
        $done = LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id, 'created_by' => $this->owner->id, 'position' => 0,
        ]);

        // Trying to sneak a done item into the open group payload.
        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$o1->id, $o2->id, $done->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $o1->fresh()->position);
        $this->assertSame(1, $o2->fresh()->position);
    }

    public function test_reorder_group_rejects_item_of_another_loop(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);
        $foreign = LoopRoadmapItem::factory()->create([
            'loop_id' => $this->otherLoop->id,
            'organization_id' => $this->otherLoop->organization_id,
            'created_by' => $this->crossUser->id,
            'status' => 'todo', 'position' => 0,
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$foreign->id, $a->id, $b->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
        $this->assertSame(0, $foreign->fresh()->position);
    }

    public function test_reorder_group_refused_for_non_member(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);

        $this->actingAs($this->nonMember);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$b->id, $a->id]);

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
    }

    public function test_reorder_group_refused_for_deactivated_member(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);
        $this->member->update(['banned_at' => now()]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$b->id, $a->id]);

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
    }

    public function test_reorder_group_covered_for_super_admin_non_member(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);

        $this->actingAs($admin);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$b->id, $a->id]);

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_reorder_group_is_transactional_no_partial_write_on_reject(): void
    {
        $a = $this->item(['status' => 'todo', 'position' => 0]);
        $b = $this->item(['status' => 'todo', 'position' => 1]);
        $c = $this->item(['status' => 'todo', 'position' => 2]);

        // Surplus (unknown id appended) -> whole call rejected, no position changes.
        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('reorderGroup', 'todo', [$c->id, $b->id, $a->id, 'not-a-real-id'])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame(0, $a->fresh()->position);
        $this->assertSame(1, $b->fresh()->position);
        $this->assertSame(2, $c->fresh()->position);
    }

    public function test_toggle_does_not_destroy_group_order(): void
    {
        $a = $this->item(['title' => 'A', 'status' => 'todo', 'position' => 0]);
        $b = $this->item(['title' => 'B', 'status' => 'todo', 'position' => 1]);
        $c = $this->item(['title' => 'C', 'status' => 'todo', 'position' => 2]);

        $this->actingAs($this->member);
        $component = Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop]);
        $component->call('reorderGroup', 'todo', [$c->id, $a->id, $b->id]);
        // Toggling B keeps the remaining open positions intact.
        $component->call('toggle', $b->id);

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame('done', $b->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Mini-kanban: inter-column move, setStatus, completed_at, create, assign B.
    // ---------------------------------------------------------------------

    public function test_move_item_across_columns_changes_status_and_renormalizes(): void
    {
        $t1 = $this->item(['title' => 'T1', 'status' => 'todo', 'position' => 0]);
        $t2 = $this->item(['title' => 'T2', 'status' => 'todo', 'position' => 1]);
        $p1 = $this->item(['title' => 'P1', 'status' => 'in_progress', 'position' => 0]);

        // Move T1 from todo into in_progress, dropped at the top.
        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('moveItem', $t1->id, 'todo', 'in_progress', [$t2->id], [$t1->id, $p1->id]);

        $this->assertSame('in_progress', $t1->fresh()->status);
        $this->assertSame(0, $t1->fresh()->position);
        $this->assertSame(1, $p1->fresh()->position);
        $this->assertSame(0, $t2->fresh()->position); // source renormalized
    }

    public function test_move_item_to_done_sets_completed_at_and_back_clears_it(): void
    {
        $t1 = $this->item(['title' => 'T1', 'status' => 'todo', 'position' => 0]);

        $this->actingAs($this->member);
        $c = Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop]);

        $c->call('moveItem', $t1->id, 'todo', 'done', [], [$t1->id]);
        $this->assertSame('done', $t1->fresh()->status);
        $this->assertNotNull($t1->fresh()->completed_at);

        $c->call('moveItem', $t1->id, 'done', 'in_progress', [], [$t1->id]);
        $this->assertSame('in_progress', $t1->fresh()->status);
        $this->assertNull($t1->fresh()->completed_at);
    }

    public function test_move_item_rejects_mismatched_sets(): void
    {
        $t1 = $this->item(['status' => 'todo', 'position' => 0]);
        $t2 = $this->item(['status' => 'todo', 'position' => 1]);
        $p1 = $this->item(['status' => 'in_progress', 'position' => 0]);

        // Source set wrong (should be [t2] after removing t1).
        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('moveItem', $t1->id, 'todo', 'in_progress', [$t1->id, $t2->id], [$t1->id, $p1->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));

        $this->assertSame('todo', $t1->fresh()->status);
    }

    public function test_move_item_rejects_same_status(): void
    {
        $t1 = $this->item(['status' => 'todo', 'position' => 0]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('moveItem', $t1->id, 'todo', 'todo', [], [$t1->id])
            ->assertSet('errorMessage', __('loops.roadmap_reorder_failed'));
    }

    public function test_move_item_refused_for_non_member(): void
    {
        $t1 = $this->item(['status' => 'todo', 'position' => 0]);

        $this->actingAs($this->nonMember);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('moveItem', $t1->id, 'todo', 'done', [], [$t1->id]);

        $this->assertSame('todo', $t1->fresh()->status);
    }

    public function test_set_status_appends_and_applies_completed_at(): void
    {
        $t1 = $this->item(['status' => 'todo', 'position' => 0]);
        $d1 = LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id, 'created_by' => $this->owner->id, 'position' => 0,
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('setStatus', $t1->id, 'done');

        $this->assertSame('done', $t1->fresh()->status);
        $this->assertNotNull($t1->fresh()->completed_at);
        $this->assertSame(1, $t1->fresh()->position); // appended after existing done item
    }

    public function test_set_status_rejects_invalid_status(): void
    {
        $t1 = $this->item(['status' => 'todo', 'position' => 0]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('setStatus', $t1->id, 'archived');

        $this->assertSame('todo', $t1->fresh()->status);
    }

    public function test_create_action_with_status_assignee_and_due(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Kickoff')
            ->set('newStatus', 'in_progress')
            ->set('newAssignees', [$this->member->id])
            ->set('newDueAt', '2026-09-01')
            ->call('createAction')
            ->assertDispatched('roadmap-action-created');

        $item = LoopRoadmapItem::where('title', 'Kickoff')->first();
        $this->assertNotNull($item);
        $this->assertSame('in_progress', $item->status);
        $this->assertEqualsCanonicalizing([$this->member->id], $item->assignees->pluck('id')->all());
    }

    public function test_create_action_rejects_non_member_assignee(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Bad assignee')
            ->set('newAssignees', [$this->nonMember->id])
            ->call('createAction')
            ->assertSet('errorMessage', __('loops.roadmap_invalid_assignee'));

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Bad assignee']);
    }

    public function test_assign_and_add_member_adds_org_user_then_assigns(): void
    {
        // An Organization user who is NOT yet a member of this Loop.
        $orgUser = User::factory()->create(['organization_id' => $this->organization->id]);
        $item = $this->item(['created_by' => $this->owner->id]);

        // Owner is privileged -> can add members.
        $this->actingAs($this->owner);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assignAndAddMember', $item->id, $orgUser->id);

        $this->assertTrue($item->fresh()->assignees->contains('id', $orgUser->id));
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $orgUser->id,
            'status' => 'active',
            'role' => 'member',
        ]);
    }

    public function test_assign_and_add_member_refused_for_regular_member(): void
    {
        $orgUser = User::factory()->create(['organization_id' => $this->organization->id]);
        // Item created by the regular member so canModify passes, but not privileged.
        $item = $this->item(['created_by' => $this->member->id]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assignAndAddMember', $item->id, $orgUser->id);

        $this->assertCount(0, $item->fresh()->assignees);
        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $orgUser->id,
        ]);
    }

    public function test_assign_and_add_member_refuses_cross_org_user(): void
    {
        $item = $this->item(['created_by' => $this->owner->id]);

        $this->actingAs($this->owner);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assignAndAddMember', $item->id, $this->crossUser->id)
            ->assertSet('errorMessage', __('loops.roadmap_invalid_assignee'));

        $this->assertCount(0, $item->fresh()->assignees);
        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->crossUser->id,
        ]);
    }
}
