<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferralReward>
 */
class ReferralRewardFactory extends Factory
{
    protected $model = ReferralReward::class;

    public function definition(): array
    {
        return [
            'referral_id' => Referral::factory(),
            'user_id' => User::factory(),
            'event_type' => 'member_invited',
            'level' => 1,
            'points' => 10,
        ];
    }

    public function forOrganization(Organization $org): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $org->id,
        ]);
    }
}
