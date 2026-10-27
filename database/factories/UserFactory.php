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
            'name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // TASK-1714 — approuve par defaut, pour la MEME raison que
            // `email_verified_at` juste au-dessus : un compte de fabrique
            // represente un compte en etat de servir, et la quasi-totalite des
            // tests le veut utilisable.
            //
            // Le laisser a `null` avait un cout mesurable : `attendApprobation()`
            // court-circuite sur `approved_at !== null`, et sans ce
            // court-circuit il CHARGE la relation `organization`. Une requete de
            // plus par requete HTTP, sur chaque compte de fabrique — c'est ce
            // qu'avait attrape la garde de comptage de TASK-1226 (38 contre 37).
            'approved_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'points_balance' => 100,
            'is_available' => true,
            'is_admin' => false,
            'banned_at' => null,
            'rating' => null,
            'bio' => fake()->sentence(6),
            'city' => fake()->city(),
            'country_code' => 'FR',
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
     * TASK-1714 — le compte attend la validation d'un administrateur.
     *
     * Il ne suffit pas a lui seul a produire un compte bloque : encore faut-il
     * que son Organization porte `requires_account_approval`. C'est delibere —
     * `attendApprobation()` lit le reglage de l'Organization, et une TASK
     * future qui l'oublierait doit buter dessus plutot que de croire ce
     * nom-la.
     */
    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }

    /**
     * Indicate that the profile is complete (first_name, name, phone, city, country_code, bio required by EnsureProfileComplete middleware).
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => $attributes['first_name'] ?? fake()->firstName(),
            'bio' => $attributes['bio'] ?? fake()->sentence(6),
            'city' => $attributes['city'] ?? fake()->city(),
            'country_code' => $attributes['country_code'] ?? 'FR',
            'phone' => $attributes['phone'] ?? fake()->phoneNumber(),
        ]);
    }
}
