<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->renameColumn('name', 'name_b2c');
            $table->string('name_b2b')->nullable()->after('name_b2c');
            $table->string('service_1')->nullable()->after('name_b2b');
            $table->string('service_2')->nullable()->after('service_1');
            $table->string('service_3')->nullable()->after('service_2');
            $table->string('service_4')->nullable()->after('service_3');
            $table->string('service_5')->nullable()->after('service_4');
        });

        $mapping = [
            'Dépannage informatique' => 'Informatique',
            'Visibilité & clients' => 'Marketing',
            'Créer des supports' => 'Communication',
            'Trouver un emploi' => 'Emploi',
            'Écrire & communiquer' => 'Rédaction',
            'Lancer son activité' => 'Entrepreneuriat',
            'Outils numériques' => 'Digital',
            'Aides & démarches' => 'Vie quotidienne',
            'Entraide locale' => 'Logistique',
            'Bricolage & projets perso' => 'Loisirs & pratique',
            'Bien-être & équilibre' => 'Bien-être & quotidien',
        ];

        foreach ($mapping as $b2c => $b2b) {
            DB::table('categories')
                ->where('name_b2c', $b2c)
                ->update(['name_b2b' => $b2b]);
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_b2b')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name_b2b', 'service_1', 'service_2', 'service_3', 'service_4', 'service_5']);
            $table->renameColumn('name_b2c', 'name');
        });
    }
};
