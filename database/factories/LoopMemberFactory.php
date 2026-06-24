<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoopMember>
 */
class LoopMemberFactory extends Factory
{
    protected $model = LoopMember::class;

    public function definition(): array
    {
        return [
            'loop_id' => Loop::factory(),
            'user_id' => User::factory(),
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => 'owner']);
    }

    public function moderator(): static
    {
        return $this->state(['role' => 'moderator']);
    }

    public function invited(): static
    {
        return $this->state([
            'status' => 'invited',
            'joined_at' => null,
        ]);
    }
}
