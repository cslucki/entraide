<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $buyer = User::factory();
        $seller = User::factory();

        return [
            'service_id' => null,
            'request_id' => null,
            'buyer_id' => $buyer,
            'seller_id' => $seller,
            'points_proposed' => fake()->numberBetween(10, 200),
            'points_agreed' => null,
            'status' => 'pending',
            'buyer_confirmed_at' => null,
            'seller_confirmed_at' => null,
            'completed_at' => null,
        ];
    }

    public function forService(Service $service): static
    {
        return $this->state([
            'service_id' => $service->id,
            'seller_id' => $service->user_id,
        ]);
    }

    public function forBuyer(User $buyer): static
    {
        return $this->state(['buyer_id' => $buyer->id]);
    }

    public function forSeller(User $seller): static
    {
        return $this->state(['seller_id' => $seller->id]);
    }

    public function accepted(): static
    {
        return $this->state([
            'status' => 'accepted',
            'points_agreed' => fn (array $attributes) => $attributes['points_proposed'],
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'points_agreed' => fn (array $attributes) => $attributes['points_proposed'],
            'buyer_confirmed_at' => now()->subHour(),
            'seller_confirmed_at' => now(),
            'completed_at' => now(),
        ]);
    }

    public function refused(): static
    {
        return $this->state(['status' => 'refused']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function buyerDone(): static
    {
        return $this->state([
            'status' => 'buyer_done',
            'buyer_confirmed_at' => now(),
            'points_agreed' => fn (array $attributes) => $attributes['points_proposed'],
        ]);
    }
}
