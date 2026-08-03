<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dossier> */
class DossierFactory extends Factory
{
    protected $model = Dossier::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'owner_id' => User::factory(),
            'name' => fake()->words(3, true),
            'visibility' => 'private',
        ];
    }
}
