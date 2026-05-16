<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'referrer_user_id' => User::factory(),
            'referred_user_id' => User::factory(),
            'depth' => 1,
            'status' => 'pending',
        ];
    }

    public function forOrganization(Community $org): static
    {
        return $this->state(fn (array $attributes) => [
            'community_id' => $org->id,
        ]);
    }

    public function activated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'activated',
            'activated_at' => now(),
        ]);
    }
}
