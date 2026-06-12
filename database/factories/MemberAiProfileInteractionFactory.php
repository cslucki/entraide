<?php

namespace Database\Factories;

use App\Models\MemberAiProfile;
use App\Models\MemberAiProfileInteraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberAiProfileInteractionFactory extends Factory
{
    protected $model = MemberAiProfileInteraction::class;

    public function definition(): array
    {
        $profile = MemberAiProfile::factory()->published()->create();

        return [
            'organization_id' => $profile->organization_id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $profile->user_id,
            'visitor_user_id' => User::factory(),
            'visitor_type' => 'user',
            'provider' => 'rule_based',
            'status' => 'success',
            'question' => fake()->sentence(),
            'response' => fake()->paragraph(),
            'matched_fields' => fake()->randomElements(['skills', 'service_scope', 'help_types'], 2),
        ];
    }
}
