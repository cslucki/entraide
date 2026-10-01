<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by_user_id' => User::factory(),
            'recipient_first_name' => fake()->firstName(),
            'recipient_name' => fake()->lastName(),
            'recipient_email' => fake()->unique()->safeEmail(),
            'status' => OrganizationInvitation::STATUS_PENDING,
        ];
    }

    public function accepted(?User $user = null): static
    {
        return $this->state([
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => OrganizationInvitation::STATUS_REVOKED]);
    }

    /** Still flagged pending, but past its validity window. */
    public function expired(): static
    {
        return $this->state([
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->subDay(),
        ]);
    }
}
