<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_id' => EmailTemplate::factory(),
            'user_id' => User::factory(),
            'organization_id' => Organization::factory(),
            'to_email' => fake()->email(),
            'subject' => fake()->sentence(),
            'status' => fake()->randomElement(['sent', 'failed']),
            'error_message' => fn (array $attrs) => $attrs['status'] === 'failed' ? fake()->sentence() : null,
            'data' => ['key' => 'value'],
        ];
    }
}
