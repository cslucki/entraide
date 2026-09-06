<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1349 : opt-in EXPLICITE de publication de la Constitution d'une
 * organisation.
 *
 * Une colonne booleenne sur `organizations`, et non une table dediee : c'est
 * un reglage d'organisation parmi la quinzaine qui vivent deja la
 * (`loops_enabled`, `ai_profiles_enabled`, `subscriptions_enabled`...). Creer
 * une table pour un drapeau serait une structure de plus a joindre, a scoper
 * et a tester, pour zero information supplementaire.
 *
 * DEFAUT `false`, et ce n'est pas un detail : une Constitution d'organisation
 * reste PRIVEE tant que quelqu'un n'a pas explicitement choisi de la publier.
 * Le defaut d'une migration est le comportement de TOUTES les organisations
 * existantes le jour ou elle passe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('ai_constitution_public')->default(false)->after('ai_profiles_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ai_constitution_public');
        });
    }
};
