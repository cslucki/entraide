<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1693 bug AU — l'assistant « BouclePro IA » s'eteint PAR ORGANIZATION.
 *
 * Jusqu'ici il n'avait qu'un interrupteur PLATEFORME (`AI_SHELL_ENABLED`, une
 * variable d'environnement) : impossible de le couper pour une Organization
 * sans le couper pour toutes. Arbitrage de Cyril : une case par Organization,
 * qui agit MEME SI l'IA est allumee pour elle.
 *
 * `default(true)` : les Organizations existantes gardent exactement le
 * comportement qu'elles ont aujourd'hui. Une migration qui eteindrait
 * l'assistant partout serait un changement de produit deguise en migration.
 *
 * Voisine immediate de `ai_profiles_enabled`, le meme genre de reglage produit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('ai_shell_enabled')->default(true)->after('ai_profiles_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_shell_enabled');
        });
    }
};
