<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    protected $model = ServiceRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(3),
            'category_id' => Category::factory(),
            'delivery_mode' => fake()->randomElement(['remote', 'onsite', 'both']),
            'budget_min' => fake()->numberBetween(10, 200),
            'budget_max' => null,
            'status' => 'open',
        ];
    }

    public function withBudgetMax(): static
    {
        return $this->state(fn (array $attributes) => [
            'budget_max' => $attributes['budget_min'] + fake()->numberBetween(50, 200),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
