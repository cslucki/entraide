<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un type peut etre renomme — **son libelle**, jamais sa cle.
 *
 * `key = training` reste `training` pour toujours ; seul le mot affiche
 * change, et il peut differer d'une Organization a l'autre : « Formation » sur
 * la Plateforme, « Parcours de formation » chez l'une.
 *
 * La cle technique est ce sur quoi s'appuient `loops.type`, les presets, les
 * permissions et sept ans de donnees. La renommer casserait tout ; c'est
 * precisement pour cela que le libelle en est separe.
 *
 * Deux colonnes nullables sur la table de reglages **qui existe deja** :
 * `null` signifie « le niveau au-dessus », comme partout ailleurs dans ce
 * systeme. Un libelle absent retombe sur la traduction declaree en
 * configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_type_settings', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('loop_type');
            $table->text('description')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('loop_type_settings', function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }
};
