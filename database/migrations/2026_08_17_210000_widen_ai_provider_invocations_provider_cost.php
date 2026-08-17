<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1222 : elargit `provider_cost` de decimal(12,6) a decimal(20,10).
 *
 * Defaut decouvert par le test economique : un embedding de 3 tokens au tarif
 * catalogue (0.02 USD / 1M) coute 0.00000006 USD — six decimales l'ecrasaient
 * en 0.000000, transformant une mesure reelle en FAUX « vrai zero known ».
 * C'est precisement le mensonge que l'invariant 0 != inconnu interdit : un
 * zero affiche doit etre un zero mesure, pas un artefact de precision.
 *
 * Elargissement pur (aucune valeur existante n'est alteree) ; les montants
 * generation a 6-8 decimales restent identiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->decimal('provider_cost', 20, 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->decimal('provider_cost', 12, 6)->nullable()->change();
        });
    }
};
