<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => DefaultOrganizationResolver::resolve()?->getKey() ?? Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'points_balance' => 100,
            'is_available' => true,
            'is_admin' => false,
            'banned_at' => null,
            'rating' => null,
            'bio' => fake()->sentence(6),
            'location' => fake()->city(),
            'phone' => fake()->phoneNumber(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the profile is complete (bio, location, phone required by EnsureProfileComplete middleware).
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'bio' => $attributes['bio'] ?? fake()->sentence(6),
            'location' => $attributes['location'] ?? fake()->city(),
            'phone' => $attributes['phone'] ?? fake()->phoneNumber(),
        ]);
    }
}
