<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1506 — quel module la RACINE sert (accueil, Shell Welcome, blog,
 * annuaire, boucles). La racine resout l'Organization par defaut : le choix
 * vit donc sur elle, a cote de `homepage_template` — qui repond a une autre
 * question (quel gabarit de landing) et reste intact.
 *
 * NULL = comportement historique. Aucune Organization existante n'est
 * reecrite : une plateforme qui n'ouvre jamais l'ecran ne change pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('root_destination', 32)->nullable()->after('homepage_settings');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('root_destination');
        });
    }
};
