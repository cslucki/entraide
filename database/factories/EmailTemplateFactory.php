<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => fake()->slug(),
            'name' => fake()->words(3, true),
            'subject' => fake()->sentence(),
            'content_html' => '<p>' . fake()->paragraphs(3, true) . '</p>',
            'variables' => ['{{user_name}}', '{{app_name}}'],
        ];
    }
}
