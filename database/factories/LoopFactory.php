<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\Loop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Loop>
 */
class LoopFactory extends Factory
{
    protected $model = Loop::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'community_id' => Community::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'type' => 'custom',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => User::factory(),
        ];
    }

    public function public(): static
    {
        return $this->state(['visibility' => 'public']);
    }

    public function system(): static
    {
        return $this->state(['type' => 'system']);
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}
