<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui compose les Cards d'une Boucle, dans cette Organization.
 *
 * Deux valeurs, pas un moteur de delegation :
 *
 *   `locked`         la composition appartient aux administrateurs — le defaut,
 *                    et le comportement livre jusqu'ici ;
 *   `owner_allowed`  le proprietaire d'une Boucle peut aussi la composer.
 *
 * Une colonne plutot qu'une table de politiques : il y a une valeur par
 * Organization, elle est lue a chaque verification de droit, et une table ferait
 * une jointure pour un booleen. Si un jour la politique se ramifie, elle
 * demandera sa propre table — pas avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('loop_composition_policy', 20)->default('locked');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('loop_composition_policy');
        });
    }
};
