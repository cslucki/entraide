<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(3),
            'category_id' => Category::factory(),
            'delivery_mode' => fake()->randomElement(['remote', 'onsite', 'both']),
            'points_cost' => fake()->numberBetween(10, 500),
            'status' => 'active',
        ];
    }

    public function paused(): static
    {
        return $this->state(['status' => 'paused']);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function forCategory(Category $category): static
    {
        return $this->state(['category_id' => $category->id]);
    }
}
