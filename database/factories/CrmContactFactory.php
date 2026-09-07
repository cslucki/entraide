<?php

namespace Database\Factories;

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmContact>
 */
class CrmContactFactory extends Factory
{
    protected $model = CrmContact::class;

    public function definition(): array
    {
        $phone = '+336'.fake()->numerify('########');

        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => CrmContact::normalizeEmail(fake()->unique()->safeEmail()),
            'phone' => $phone,
            'phone_normalized' => CrmContact::normalizePhone($phone),
            'company' => fake()->optional()->company(),
            'source' => CrmContact::SOURCE_MANUAL,
        ];
    }

    public function linkedTo(User $user): static
    {
        return $this->state([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
        ]);
    }

    public function doNotContact(): static
    {
        return $this->state(['do_not_contact_at' => now()]);
    }

    public function withoutEmail(): static
    {
        return $this->state(['email' => null]);
    }
}
