<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un Module aussi peut etre archive.
 *
 * Ses Sequences l'etaient deja des qu'une progression existait — on n'efface
 * pas ce que des gens ont fait. Mais le Module, lui, restait, vide de toute
 * Sequence vivante. Or un Module vide n'est jamais « termine » : il gelait donc
 * **toute la suite du parcours, pour tout le monde**, sans retour possible.
 *
 * Vide et archive ne sont pas la meme chose, et il fallait pouvoir les
 * distinguer. Un Module vide bloque — c'est voulu, il reste a remplir. Un
 * Module archive **sort du parcours** : il ne bloque plus, il garde seulement
 * la trace de ce qui s'y est passe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
