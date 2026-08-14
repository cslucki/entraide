<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoopJoinRequest>
 */
class LoopJoinRequestFactory extends Factory
{
    protected $model = LoopJoinRequest::class;

    public function definition(): array
    {
        $loop = Loop::factory();

        return [
            'loop_id' => $loop,
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
            'user_id' => User::factory(),
            'message' => fake()->optional()->sentence(),
            'status' => LoopJoinRequest::STATUS_PENDING,
        ];
    }

    public function accepted(): static
    {
        return $this->state([
            'status' => LoopJoinRequest::STATUS_ACCEPTED,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => LoopJoinRequest::STATUS_REJECTED,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => LoopJoinRequest::STATUS_CANCELLED]);
    }
}
