<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        return [
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
