<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('countries')->upsert(array_map(
            fn (array $country): array => [
                'code' => $country[0],
                'name_fr' => $country[1],
                'name_en' => $country[2],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::countries()
        ), ['code'], ['name_fr', 'name_en', 'active', 'updated_at']);
    }

    public function down(): void
    {
        // Keep countries stable; this data migration is intentionally non-destructive.
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private static function countries(): array
    {
        return [
            ['LU', 'Luxembourg', 'Luxembourg'],
            ['MC', 'Monaco', 'Monaco'],
            ['AD', 'Andorre', 'Andorra'],
            ['NL', 'Pays-Bas', 'Netherlands'],
            ['PT', 'Portugal', 'Portugal'],
            ['IE', 'Irlande', 'Ireland'],
            ['AT', 'Autriche', 'Austria'],
            ['SE', 'Suede', 'Sweden'],
            ['NO', 'Norvege', 'Norway'],
            ['DK', 'Danemark', 'Denmark'],
            ['FI', 'Finlande', 'Finland'],
            ['PL', 'Pologne', 'Poland'],
            ['GR', 'Grece', 'Greece'],
            ['RO', 'Roumanie', 'Romania'],
            ['MX', 'Mexique', 'Mexico'],
            ['BR', 'Bresil', 'Brazil'],
            ['AR', 'Argentine', 'Argentina'],
            ['AU', 'Australie', 'Australia'],
            ['NZ', 'Nouvelle-Zelande', 'New Zealand'],
            ['JP', 'Japon', 'Japan'],
            ['CN', 'Chine', 'China'],
            ['IN', 'Inde', 'India'],
            ['KR', 'Coree du Sud', 'South Korea'],
            ['ZA', 'Afrique du Sud', 'South Africa'],
            ['NG', 'Nigeria', 'Nigeria'],
            ['KE', 'Kenya', 'Kenya'],
            ['RW', 'Rwanda', 'Rwanda'],
            ['BJ', 'Benin', 'Benin'],
            ['BF', 'Burkina Faso', 'Burkina Faso'],
            ['ML', 'Mali', 'Mali'],
            ['NE', 'Niger', 'Niger'],
            ['TG', 'Togo', 'Togo'],
            ['GA', 'Gabon', 'Gabon'],
            ['CG', 'Congo', 'Congo'],
            ['CD', 'Republique democratique du Congo', 'Democratic Republic of the Congo'],
            ['GN', 'Guinee', 'Guinea'],
            ['MR', 'Mauritanie', 'Mauritania'],
            ['MU', 'Ile Maurice', 'Mauritius'],
            ['KM', 'Comores', 'Comoros'],
            ['SC', 'Seychelles', 'Seychelles'],
            ['HT', 'Haiti', 'Haiti'],
            ['LB', 'Liban', 'Lebanon'],
        ];
    }
};
