<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopRoadmapItemTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);

        $this->loop = (new LoopService)->createLoop($this->owner, 'Roadmap Loop');
    }

    public function test_item_belongs_to_loop_organization_and_creator(): void
    {
        $item = LoopRoadmapItem::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'title' => 'Prévisionnel budgétaire',
            'status' => LoopRoadmapItem::STATUS_OPEN,
            'position' => 0,
            'created_by' => $this->owner->id,
        ]);

        $this->assertSame($this->loop->id, $item->loop->id);
        $this->assertSame($this->organization->id, $item->organization->id);
        $this->assertSame($this->owner->id, $item->creator->id);
        $this->assertNull($item->assignee);
        $this->assertFalse($item->isDone());
    }

    public function test_assignee_relation_is_nullable(): void
    {
        $assignee = User::factory()->create(['organization_id' => $this->organization->id]);

        $item = LoopRoadmapItem::factory()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id,
            'assigned_to' => $assignee->id,
        ]);

        $this->assertSame($assignee->id, $item->assignee->id);
    }

    public function test_done_state_and_completed_at(): void
    {
        $item = LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id,
        ]);

        $this->assertTrue($item->isDone());
        $this->assertNotNull($item->completed_at);
    }

    public function test_ordered_scope_puts_open_before_done_then_position(): void
    {
        $done = LoopRoadmapItem::factory()->done()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id, 'position' => 0, 'title' => 'Done A',
        ]);
        $openB = LoopRoadmapItem::factory()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id, 'position' => 2, 'title' => 'Open pos2',
        ]);
        $openA = LoopRoadmapItem::factory()->create([
            'loop_id' => $this->loop->id, 'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id, 'position' => 1, 'title' => 'Open pos1',
        ]);

        $ordered = LoopRoadmapItem::query()->where('loop_id', $this->loop->id)->ordered()->pluck('id')->all();

        $this->assertSame([$openA->id, $openB->id, $done->id], $ordered);
    }
}
