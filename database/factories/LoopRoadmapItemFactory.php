<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoopRoadmapItem>
 */
class LoopRoadmapItemFactory extends Factory
{
    protected $model = LoopRoadmapItem::class;

    public function definition(): array
    {
        return [
            'loop_id' => Loop::factory(),
            // Keep organization_id consistent with the parent loop.
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
            'title' => fake()->sentence(3),
            'status' => LoopRoadmapItem::STATUS_TODO,
            'position' => 0,
            'due_at' => null,
            'created_by' => User::factory(),
            'completed_at' => null,
        ];
    }

    public function todo(): static
    {
        return $this->state(fn () => ['status' => LoopRoadmapItem::STATUS_TODO, 'completed_at' => null]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => LoopRoadmapItem::STATUS_IN_PROGRESS, 'completed_at' => null]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => LoopRoadmapItem::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->afterCreating(fn (LoopRoadmapItem $item) => $item->assignees()->syncWithoutDetaching([$user->id]));
    }
}
