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
            ->call('add')
            ->assertSet('newTitle', '')
            ->assertSee('Rédiger le prévisionnel');

        $this->assertDatabaseHas('loop_roadmap_items', [
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'title' => 'Rédiger le prévisionnel',
            'status' => 'open',
            'created_by' => $this->member->id,
        ]);
    }

    public function test_add_requires_a_title(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', '   ')
            ->call('add')
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
        $this->assertSame('open', $item->fresh()->status);
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
            'status' => 'open',
        ]);

        $this->actingAs($this->owner);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])->call('toggle', $foreign->id);

        // Unchanged: the foreign id is not resolvable within this loop/org.
        $this->assertSame('open', $foreign->fresh()->status);
    }

    public function test_assignee_must_be_active_member_of_the_loop(): void
    {
        $item = $this->item(['created_by' => $this->owner->id]);
        $this->actingAs($this->owner);

        // Valid: assign to an active member
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingAssignee', $this->member->id)
            ->call('saveEdit');
        $this->assertSame($this->member->id, $item->fresh()->assigned_to);

        // Invalid: assign to a non-member -> refused, error message
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('startEdit', $item->id)
            ->set('editingAssignee', $this->nonMember->id)
            ->call('saveEdit')
            ->assertSet('errorMessage', __('loops.roadmap_invalid_assignee'));
        $this->assertSame($this->member->id, $item->fresh()->assigned_to);
    }

    public function test_non_member_cannot_manage(): void
    {
        $this->actingAs($this->nonMember);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->assertSet('newTitle', '')
            ->set('newTitle', 'Should not persist')
            ->call('add');

        $this->assertDatabaseCount('loop_roadmap_items', 0);
    }

    public function test_deactivated_member_cannot_add(): void
    {
        $this->member->update(['banned_at' => now()]);
        $this->actingAs($this->member);

        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Nope')
            ->call('add');

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
        $this->item(['status' => 'open', 'position' => 0]);
        $this->item(['status' => 'open', 'position' => 1]);
        LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id, 'created_by' => $this->owner->id,
        ]);

        $this->actingAs($this->member);
        Livewire::test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->assertViewHas('openCount', 2);
    }
}
