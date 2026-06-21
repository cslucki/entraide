<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name_b2c' => $name,
            'name_b2b' => $name,
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
            'organization_id' => Organization::factory(),
        ];
    }
}
