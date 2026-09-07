<?php

namespace Database\Factories;

use App\Models\CrmStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmStatus>
 */
class CrmStatusFactory extends Factory
{
    protected $model = CrmStatus::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'label' => ucfirst(fake()->unique()->words(2, true)),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_active' => true,
            'is_default' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
