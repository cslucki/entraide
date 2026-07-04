<?php

namespace Database\Factories;

use App\Models\MemberAiProfile;
use App\Models\ProfileAgentConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfileAgentConversationFactory extends Factory
{
    protected $model = ProfileAgentConversation::class;

    public function definition(): array
    {
        $profile = MemberAiProfile::factory()->published()->create();

        return [
            'organization_id' => $profile->organization_id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $profile->user_id,
            'visitor_user_id' => User::factory(),
            'visitor_session_id' => null,
            'title' => fake()->sentence(3),
        ];
    }

    public function anonymousVisitor(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_user_id' => null,
            'visitor_session_id' => fake()->uuid(),
        ]);
    }
}
