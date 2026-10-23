<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1693 bug AW — OU l'assistant « BouclePro IA » apparait, surface par
 * surface.
 *
 * Le bug AU posait un tout-ou-rien par Organization. Demande de Cyril : un
 * tableau Organizations x surfaces.
 *
 * ## Pourquoi stocker les surfaces REFUSEES, et non les autorisees
 *
 * Une liste d'AUTORISEES rendrait toute surface ajoutee demain INVISIBLE par
 * defaut — un ecran neuf naitrait muet, sans que personne ait rien decide.
 * Une liste de REFUSEES fait l'inverse : ce qui n'a jamais ete refuse reste
 * visible, et le defaut du produit continue de s'appliquer.
 *
 * `nullable` : une Organization qui n'a jamais rien decide porte `null`, ce
 * qui se lit « aucune surface refusee ». Aucune donnee n'est ecrite par cette
 * migration — elle ne change le comportement d'AUCUNE Organization existante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('ai_shell_disabled_surfaces')->nullable()->after('ai_shell_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_shell_disabled_surfaces');
        });
    }
};
