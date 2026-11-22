<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1885 C7 (demande Cyril, 22/11) — le « Pitch » d'une Boucle : un mot
 * court (12 caracteres max) affiche en grand, en surimpression de sa
 * couverture. Colonne nullable, additive : aucune donnee existante touchee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loops', function (Blueprint $table): void {
            $table->string('pitch', 12)->nullable()->after('tagline');
        });
    }

    public function down(): void
    {
        Schema::table('loops', function (Blueprint $table): void {
            $table->dropColumn('pitch');
        });
    }
};
