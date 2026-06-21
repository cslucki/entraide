<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'transaction_id' => null,
            'reviewer_id' => User::factory(),
            'reviewed_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->paragraph(),
        ];
    }

    public function forTransaction(Transaction $transaction): static
    {
        return $this->state(['transaction_id' => $transaction->id]);
    }
}
