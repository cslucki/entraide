<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\LoopRoadmapItemMessage;
use App\Models\LoopRoadmapLabel;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopRoadmapExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Extras Loop');
    }

    private function item(): LoopRoadmapItem
    {
        return LoopRoadmapItem::factory()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->loop->organization_id,
            'created_by' => $this->owner->id,
        ]);
    }

    private function label(string $name = 'Urgent', string $color = 'red'): LoopRoadmapLabel
    {
        return LoopRoadmapLabel::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'name' => $name,
            'color' => $color,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_description_is_nullable_and_persists(): void
    {
        $item = $this->item();
        $this->assertNull($item->description);

        $item->update(['description' => '<p>Hello <strong>world</strong></p>']);
        $this->assertSame('<p>Hello <strong>world</strong></p>', $item->fresh()->description);
    }

    public function test_labels_attach_and_detach(): void
    {
        $item = $this->item();
        $l1 = $this->label('Urgent', 'red');
        $l2 = $this->label('Backend', 'blue');

        $item->labels()->sync([$l1->id, $l2->id]);
        $this->assertCount(2, $item->fresh()->labels);

        $item->labels()->detach($l1->id);
        $this->assertEqualsCanonicalizing([$l2->id], $item->fresh()->labels->pluck('id')->all());
    }

    public function test_label_belongs_to_its_loop_and_organization(): void
    {
        $label = $this->label();
        $this->assertSame($this->loop->id, $label->loop->id);
        $this->assertSame($this->organization->id, $label->organization->id);
        $this->assertTrue(LoopRoadmapLabel::isValidColor('violet'));
        $this->assertFalse(LoopRoadmapLabel::isValidColor('#ff0000'));
    }

    public function test_messages_relation_and_ordering(): void
    {
        $item = $this->item();

        $first = LoopRoadmapItemMessage::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'loop_roadmap_item_id' => $item->id,
            'user_id' => $this->owner->id,
            'body' => 'First',
        ]);
        $second = LoopRoadmapItemMessage::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'loop_roadmap_item_id' => $item->id,
            'user_id' => $this->owner->id,
            'body' => 'Second',
        ]);

        // messages() is latest-first.
        $this->assertSame([$second->id, $first->id], $item->fresh()->messages->pluck('id')->all());
        $this->assertSame($item->id, $first->item->id);
    }

    public function test_message_is_soft_deleted(): void
    {
        $item = $this->item();
        $msg = LoopRoadmapItemMessage::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'loop_roadmap_item_id' => $item->id,
            'user_id' => $this->owner->id,
            'body' => 'Bye',
        ]);

        $msg->delete();
        $this->assertSoftDeleted('loop_roadmap_item_messages', ['id' => $msg->id]);
        $this->assertCount(0, $item->fresh()->messages);
    }
}
