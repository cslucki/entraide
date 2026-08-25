<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1306 : qui gere le credential IA d'une Organization —
 * `platform_managed` (le SuperAdmin le definit/remplace, l'admin
 * d'Organization ne voit ni ne modifie le champ) ou `organization_managed`
 * (comportement TASK-1212 inchange : l'admin d'Organization gere sa propre
 * cle). Additive, jamais nulle : une ligne existante sans valeur explicite
 * est `platform_managed` par defaut — c'est deja son etat de fait (aucune
 * Organization ne gerait son propre credential avant cette TASK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table) {
            $table->string('credential_management_mode', 30)
                ->default('platform_managed')
                ->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table) {
            $table->dropColumn('credential_management_mode');
        });
    }
};
