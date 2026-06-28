<?php

namespace Database\Factories;

use App\Models\SystemEmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemEmailTemplateFactory extends Factory
{
    protected $model = SystemEmailTemplate::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->slug(),
            'name' => fake()->words(3, true),
            'subject' => fake()->sentence(),
            'content_html' => '<p>'.fake()->paragraphs(3, true).'</p>',
            'variables' => ['name', 'email'],
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
