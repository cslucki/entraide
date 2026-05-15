<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoopMessage>
 */
class LoopMessageFactory extends Factory
{
    protected $model = LoopMessage::class;

    public function definition(): array
    {
        return [
            'loop_id' => Loop::factory(),
            'sender_id' => User::factory(),
            'body' => fake()->paragraph(),
            'type' => 'user',
            'metadata' => null,
        ];
    }

    public function system(): static
    {
        return $this->state([
            'sender_id' => null,
            'type' => 'system',
        ]);
    }
}
