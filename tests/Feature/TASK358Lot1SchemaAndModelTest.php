<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TASK358Lot1SchemaAndModelTest extends TestCase
{
    public function test_legacy_user_columns_are_preserved_and_new_profile_columns_exist(): void
    {
        foreach (['name', 'location'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column));
        }

        foreach ([
            'first_name',
            'address_line1',
            'address_line2',
            'postal_code',
            'city',
            'country_code',
            'preferred_locale',
            'membership_value',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column));
        }
    }

    public function test_reference_countries_are_available_after_migrations(): void
    {
        $expectedCodes = [
            'FR', 'US', 'GB', 'CA', 'BE', 'CH', 'DE', 'ES', 'IT', 'MA', 'TN', 'DZ', 'SN', 'CI', 'CM', 'MG',
            'LU', 'MC', 'AD', 'NL', 'PT', 'IE', 'AT', 'SE', 'NO', 'DK', 'FI', 'PL', 'GR', 'RO',
            'MX', 'BR', 'AR', 'AU', 'NZ', 'JP', 'CN', 'IN', 'KR', 'ZA', 'NG', 'KE', 'RW', 'BJ', 'BF',
            'ML', 'NE', 'TG', 'GA', 'CG', 'CD', 'GN', 'MR', 'MU', 'KM', 'SC', 'HT', 'LB',
        ];

        $countries = Country::whereIn('code', $expectedCodes)->get();

        $this->assertGreaterThanOrEqual(45, Country::where('active', true)->count());
        $this->assertEmpty(array_diff($expectedCodes, $countries->pluck('code')->all()));
        $this->assertTrue($countries->every(fn (Country $country): bool => strlen($country->code) === 2));
        $this->assertTrue($countries->every(fn (Country $country): bool => filled($country->name_fr) && filled($country->name_en)));
    }

    public function test_country_reference_upsert_can_be_replayed_without_duplicates(): void
    {
        $beforeCount = Country::count();

        DB::table('countries')->upsert([
            ['code' => 'FR', 'name_fr' => 'France', 'name_en' => 'France', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'US', 'name_fr' => 'Etats-Unis', 'name_en' => 'United States', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ], ['code'], ['name_fr', 'name_en', 'active', 'updated_at']);

        $this->assertSame($beforeCount, Country::count());
    }

    public function test_new_user_fields_are_fillable_and_persisted(): void
    {
        $organization = Organization::factory()->create();

        $user = User::create([
            'organization_id' => $organization->id,
            'name' => 'Jane Doe',
            'first_name' => 'Jane',
            'email' => 'jane@example.test',
            'password' => 'password',
            'bio' => 'Bio test',
            'location' => 'Legacy location',
            'phone' => '+33123456789',
            'address_line1' => '1 rue Exemple',
            'address_line2' => 'Batiment B',
            'postal_code' => '75001',
            'city' => 'Paris',
            'country_code' => 'FR',
            'preferred_locale' => 'fr',
            'membership_value' => 'Membre fondateur',
        ]);

        $user->refresh();

        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('1 rue Exemple', $user->address_line1);
        $this->assertSame('Batiment B', $user->address_line2);
        $this->assertSame('75001', $user->postal_code);
        $this->assertSame('Paris', $user->city);
        $this->assertSame('FR', $user->country_code);
        $this->assertSame('fr', $user->preferred_locale);
        $this->assertSame('Membre fondateur', $user->membership_value);
        $this->assertSame('Paris, France', $user->public_location);
    }

    public function test_user_public_location_uses_city_and_country_without_legacy_location_fallback(): void
    {
        $organization = Organization::factory()->create(['show_country' => false]);
        $user = User::factory()->for($organization)->create([
            'city' => 'Lyon',
            'country_code' => 'FR',
            'location' => 'Legacy Lyon',
        ]);

        $this->assertSame('Lyon', $user->fresh(['organization', 'country'])->public_location);

        $user->update(['city' => null]);

        $this->assertNull($user->fresh(['organization', 'country'])->public_location);
    }

    public function test_organization_country_and_membership_config_are_persisted(): void
    {
        $organization = Organization::factory()->create([
            'default_country_code' => 'FR',
            'membership_enabled' => true,
            'membership_label_fr' => 'Entreprise',
            'membership_label_en' => 'Company',
        ]);

        $organization->refresh();

        $this->assertTrue($organization->show_country);
        $this->assertTrue($organization->membership_enabled);
        $this->assertSame('FR', $organization->default_country_code);
        $this->assertSame('Entreprise', $organization->membership_label_fr);
        $this->assertSame('Company', $organization->membership_label_en);
        $this->assertSame('France', $organization->defaultCountry->name_fr);
    }

    public function test_organization_priority_countries_pivot_works(): void
    {
        $organization = Organization::factory()->create();

        $organization->priorityCountries()->attach('FR', ['sort_order' => 20]);
        $organization->priorityCountries()->attach('CA', ['sort_order' => 10]);

        $this->assertSame(['CA', 'FR'], $organization->fresh()->priorityCountries->pluck('code')->all());
    }
}
