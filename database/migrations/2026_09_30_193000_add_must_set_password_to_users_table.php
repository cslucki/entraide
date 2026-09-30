<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // TASK-1659 — un compte ne au clic d'une invitation porte un
            // secret ALEATOIRE que personne ne connait : la personne doit
            // poser son mot de passe avant d'aller plus loin, sinon elle
            // n'y reviendra jamais. Le drapeau vit en base, pas en session :
            // fermer l'onglet ne doit pas faire sauter l'etape.
            //
            // `false` par defaut : aucun compte existant n'est concerne.
            $table->boolean('must_set_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_set_password');
        });
    }
};
