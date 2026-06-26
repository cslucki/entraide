<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name_fr');
            $table->string('name_en');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('countries')->upsert([
            ['code' => 'FR', 'name_fr' => 'France', 'name_en' => 'France', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'US', 'name_fr' => 'Etats-Unis', 'name_en' => 'United States', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GB', 'name_fr' => 'Royaume-Uni', 'name_en' => 'United Kingdom', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CA', 'name_fr' => 'Canada', 'name_en' => 'Canada', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BE', 'name_fr' => 'Belgique', 'name_en' => 'Belgium', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CH', 'name_fr' => 'Suisse', 'name_en' => 'Switzerland', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DE', 'name_fr' => 'Allemagne', 'name_en' => 'Germany', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ES', 'name_fr' => 'Espagne', 'name_en' => 'Spain', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'IT', 'name_fr' => 'Italie', 'name_en' => 'Italy', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MA', 'name_fr' => 'Maroc', 'name_en' => 'Morocco', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TN', 'name_fr' => 'Tunisie', 'name_en' => 'Tunisia', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'DZ', 'name_fr' => 'Algerie', 'name_en' => 'Algeria', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SN', 'name_fr' => 'Senegal', 'name_en' => 'Senegal', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CI', 'name_fr' => 'Cote d Ivoire', 'name_en' => 'Ivory Coast', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CM', 'name_fr' => 'Cameroun', 'name_en' => 'Cameroon', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'MG', 'name_fr' => 'Madagascar', 'name_en' => 'Madagascar', 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name_fr', 'name_en', 'active', 'updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
