<?php

namespace Database\Factories;

use App\Models\ProfileAgentConversation;
use App\Models\ProfileAgentMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfileAgentMessageFactory extends Factory
{
    protected $model = ProfileAgentMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => ProfileAgentConversation::factory(),
            'role' => fake()->randomElement(['user', 'assistant']),
            'content' => fake()->paragraph(),
            'created_at' => now(),
        ];
    }

    public function userMessage(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'user',
            'content' => fake()->sentence(),
            'metadata' => null,
        ]);
    }

    public function assistantMessage(?array $metadata = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'content' => fake()->paragraph(),
            'metadata' => $metadata ?? [
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'latency_ms' => fake()->numberBetween(200, 3000),
            ],
        ]);
    }
}
