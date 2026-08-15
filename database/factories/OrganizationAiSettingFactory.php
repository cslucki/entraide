<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationAiSetting>
 */
class OrganizationAiSettingFactory extends Factory
{
    protected $model = OrganizationAiSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-test-'.fake()->sha1(),
            'monthly_budget_usd' => null,
            'is_enabled' => true,
            'api_key_updated_at' => now(),
        ];
    }

    public function withoutCredential(): static
    {
        return $this->state(fn () => ['api_key' => null, 'api_key_updated_at' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
