<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'transaction_id' => null,
            'sender_id' => User::factory(),
            'body' => fake()->paragraph(),
            'type' => 'user',
            'read_at' => null,
        ];
    }

    public function forTransaction(Transaction $transaction): static
    {
        return $this->state(['transaction_id' => $transaction->id]);
    }

    public function system(): static
    {
        return $this->state(['sender_id' => null, 'type' => 'system']);
    }
}
