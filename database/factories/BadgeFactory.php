<?php

namespace Database\Factories;

use App\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    public function definition(): array
    {
        return [
            'key'         => $this->faker->unique()->word(),
            'name'        => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'icon'        => 'star',
            'color'       => $this->faker->hexColor(),
        ];
    }
}
