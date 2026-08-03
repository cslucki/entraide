<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoopInvitation>
 */
class LoopInvitationFactory extends Factory
{
    protected $model = LoopInvitation::class;

    public function definition(): array
    {
        $loop = Loop::factory();

        return [
            'loop_id' => $loop,
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
            'sender_id' => User::factory(),
            'recipient_email' => fake()->unique()->safeEmail(),
            'recipient_name' => fake()->optional()->name(),
            'message' => fake()->optional()->sentence(),
            'invitation_type' => LoopInvitation::TYPE_EXTERNAL,
            'status' => LoopInvitation::STATUS_PENDING,
        ];
    }

    public function accepted(?User $user = null): static
    {
        return $this->state([
            'status' => LoopInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => LoopInvitation::STATUS_REVOKED]);
    }

    /** Still flagged pending, but past its validity window. */
    public function expired(): static
    {
        return $this->state([
            'status' => LoopInvitation::STATUS_PENDING,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function forExistingMember(): static
    {
        return $this->state(['invitation_type' => LoopInvitation::TYPE_EXISTING_MEMBER]);
    }
}
