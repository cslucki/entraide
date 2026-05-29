<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => Str::slug($this->faker->unique()->word()),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_public' => false,
            'admin_id' => null,
            'hero_image' => null,
            'hero_title' => null,
            'hero_description' => null,
            'accent_color' => '#6366f1',
            'welcome_points' => 100,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withHero(): static
    {
        return $this->state([
            'hero_title' => 'Bienvenue',
            'hero_description' => fake()->paragraph(),
            'accent_color' => '#10b981',
        ]);
    }
}
